<?php
/** Property engine: ownership resolution, unit status, party helpers. */

function bill_types(bool $activeOnly = true): array {
    static $c = [];
    $k = (int)$activeOnly;
    if (!isset($c[$k])) { $c[$k] = []; foreach (all("SELECT * FROM cfg_bill_types" . ($activeOnly ? " WHERE is_active = 1" : '') . " ORDER BY sort_order, name") as $r) $c[$k][(int)$r['id']] = $r; }
    return $c[$k];
}
function bill_type(int $id): ?array { return bill_types(false)[$id] ?? null; }
function bill_type_by_code(string $code): ?array { foreach (bill_types(false) as $b) if ($b['code'] === $code) return $b; return null; }

/**
 * Current owners of a unit with their share. Most specific scope wins:
 * unit-level ownership → block-level → whole-property.
 * @return array<int, array{party_id:int, share_pct:float, display_name:string, scope:string}>
 */
function unit_owners(int $unitId, ?string $onDate = null): array {
    $onDate = $onDate ?? date('Y-m-d');
    $u = one("SELECT id, property_id, block_id FROM prpt_units WHERE id = ?", [$unitId]);
    if (!$u) return [];
    $base = "SELECT o.party_id, o.share_pct, o.scope, p.display_name, p.email, p.phone FROM prpt_ownerships o JOIN prpt_parties p ON p.id = o.party_id
             WHERE o.start_date <= :d AND (o.end_date IS NULL OR o.end_date >= :d) AND ";
    $rows = all($base . "o.scope = 'unit' AND o.unit_id = :u", ['d' => $onDate, 'u' => $unitId]);
    if (!$rows && $u['block_id']) $rows = all($base . "o.scope = 'block' AND o.block_id = :b", ['d' => $onDate, 'b' => $u['block_id']]);
    if (!$rows) $rows = all($base . "o.scope = 'property' AND o.property_id = :p", ['d' => $onDate, 'p' => $u['property_id']]);
    return array_map(fn($r) => ['party_id' => (int)$r['party_id'], 'share_pct' => (float)$r['share_pct'], 'display_name' => $r['display_name'], 'scope' => $r['scope'], 'email' => $r['email'], 'phone' => $r['phone']], $rows);
}
function unit_primary_owner_id(int $unitId): ?int { $o = unit_owners($unitId); return $o ? $o[0]['party_id'] : null; }

/** Units a party owns (any scope), with share. */
function party_owned_units(int $partyId): array {
    $own = all("SELECT * FROM prpt_ownerships WHERE party_id = ? AND (end_date IS NULL OR end_date >= CURDATE())", [$partyId]);
    $units = [];
    foreach ($own as $o) {
        $ids = match ($o['scope']) {
            'unit' => [(int)$o['unit_id']],
            'block' => array_map('intval', col("SELECT id FROM prpt_units WHERE block_id = ? AND deleted_at IS NULL", [$o['block_id']])),
            default => array_map('intval', col("SELECT id FROM prpt_units WHERE property_id = ? AND deleted_at IS NULL", [$o['property_id']])),
        };
        foreach ($ids as $id) {
            // only count if this ownership is the effective one for the unit
            foreach (unit_owners($id) as $eo) if ($eo['party_id'] === $partyId) $units[$id] = $eo['share_pct'];
        }
    }
    return $units; // [unit_id => share_pct]
}

/** Active billing account (lease) for a unit */
function unit_active_lease(int $unitId): ?array {
    return one("SELECT * FROM bln_leases WHERE (unit_id = ? OR id IN (SELECT lease_id FROM bln_lease_units WHERE unit_id = ?)) AND status IN ('active','notice') ORDER BY start_date DESC LIMIT 1", [$unitId, $unitId]);
}

function set_unit_status(int $unitId, string $status, ?string $reason = null): void {
    $cur = val("SELECT status FROM prpt_units WHERE id = ?", [$unitId]);
    if ($cur === $status) return;
    q("UPDATE prpt_units SET status = ? WHERE id = ?", [$status, $unitId]);
    insert('prpt_unit_status_log', ['unit_id' => $unitId, 'from_status' => $cur, 'to_status' => $status, 'reason' => $reason, 'user_id' => staff_id()]);
}

function unit_label(array $u): string { return trim(($u['block_code'] ?? '') ? $u['block_code'] . '-' . $u['unit_no'] : $u['unit_no']); }

function party_create(array $d): int {
    $display = trim(($d['party_type'] ?? 'individual') === 'company' ? ($d['company_name'] ?? '') : trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? '')));
    $id = insert('prpt_parties', [
        'uuid' => uuid4(), 'party_no' => next_no('party'), 'party_type' => $d['party_type'] ?? 'individual',
        'first_name' => nullable($d['first_name'] ?? null), 'last_name' => nullable($d['last_name'] ?? null), 'company_name' => nullable($d['company_name'] ?? null),
        'display_name' => $display ?: 'Unnamed', 'is_owner' => (int)!empty($d['is_owner']), 'is_tenant' => (int)!empty($d['is_tenant']),
        'is_guest' => (int)!empty($d['is_guest']), 'is_buyer' => (int)!empty($d['is_buyer']),
        'id_type' => nullable($d['id_type'] ?? null), 'id_number' => nullable($d['id_number'] ?? null), 'kra_pin' => nullable($d['kra_pin'] ?? null),
        'email' => nullable(strtolower(trim((string)($d['email'] ?? '')))), 'phone' => normalize_phone($d['phone'] ?? null) ?? nullable($d['phone'] ?? null),
        'alt_phone' => nullable($d['alt_phone'] ?? null), 'nationality' => nullable($d['nationality'] ?? 'Kenyan'),
        'postal_address' => nullable($d['postal_address'] ?? null), 'physical_address' => nullable($d['physical_address'] ?? null),
        'occupation' => nullable($d['occupation'] ?? null), 'employer' => nullable($d['employer'] ?? null), 'date_of_birth' => nullable($d['date_of_birth'] ?? null),
        'gender' => nullable($d['gender'] ?? null),
        'next_of_kin_name' => nullable($d['next_of_kin_name'] ?? null), 'next_of_kin_phone' => nullable($d['next_of_kin_phone'] ?? null), 'next_of_kin_relation' => nullable($d['next_of_kin_relation'] ?? null),
        'preferred_channel' => $d['preferred_channel'] ?? 'email', 'notes' => nullable($d['notes'] ?? null),
    ]);
    // every owner/tenant gets a (pending) self-service portal account
    if (!empty($d['is_owner']) || !empty($d['is_tenant'])) portal_ensure_account($id);
    audit('property', 'create', 'party', $id, 'Created ' . $display);
    return $id;
}
