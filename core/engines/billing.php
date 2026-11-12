<?php
/**
 * Billing engine: leases (billing accounts), auto/manual invoicing, metered billing,
 * credits consumption, penalties, invoice timeline.
 */

/* ------------------------------------------------------------------ leases */
/** Suggested charges for a new account (pre-filled in the UI, all editable). */
function lease_default_charges(int $unitId, string $occupancy, ?int $tenantId, ?int $ownerId): array {
    $u = one("SELECT * FROM prpt_units WHERE id = ?", [$unitId]);
    $out = [];
    $payerFor = function (array $bt) use ($occupancy, $tenantId, $ownerId) {
        $ownerPays = in_array($occupancy, ['owner_occupied', 'owner_vacant'], true) || !$tenantId;
        if ($ownerPays) return $ownerId;
        return $bt['default_bill_to'] === 'owner' ? ($ownerId ?? $tenantId) : $tenantId;
    };
    if (in_array($occupancy, ['tenant_managed', 'company_let', 'anonymous'], true) && (float)$u['rent_amount'] > 0) {
        $bt = bill_type_by_code('RENT');
        $out[] = ['bill_type_id' => (int)$bt['id'], 'billing_method' => 'fixed', 'amount' => (float)$u['rent_amount'], 'unit_rate' => null, 'frequency' => 'monthly', 'bill_to_party_id' => $tenantId];
    }
    if ((float)$u['service_charge'] > 0) {
        $bt = bill_type_by_code('SC');
        $out[] = ['bill_type_id' => (int)$bt['id'], 'billing_method' => 'fixed', 'amount' => (float)$u['service_charge'], 'unit_rate' => null, 'frequency' => 'monthly', 'bill_to_party_id' => $payerFor($bt)];
    }
    $pcs = all("SELECT pc.*, bt.code, bt.billing_method AS bt_method, bt.frequency AS bt_freq, bt.default_unit_rate, bt.default_amount, bt.default_bill_to
                FROM prpt_property_charges pc JOIN cfg_bill_types bt ON bt.id = pc.bill_type_id
                WHERE pc.property_id = ? AND (pc.applies_to = 'all' OR pc.applies_to = ?) AND bt.code NOT IN ('RENT','SC') AND bt.category NOT IN ('deposit')", [$u['property_id'], $u['usage_class']]);
    foreach ($pcs as $pc) {
        $hasMeter = $pc['bt_method'] !== 'metered' || val("SELECT 1 FROM prpt_meters WHERE unit_id = ? AND bill_type_id = ? AND status = 'active'", [$unitId, $pc['bill_type_id']]);
        if (!$hasMeter) continue;
        $bt = ['default_bill_to' => $pc['bill_to'] ?: $pc['default_bill_to']];
        $out[] = ['bill_type_id' => (int)$pc['bill_type_id'], 'billing_method' => $pc['bt_method'], 'amount' => $pc['amount'] ?? $pc['default_amount'],
                  'unit_rate' => $pc['unit_rate'] ?? $pc['default_unit_rate'], 'frequency' => $pc['bt_freq'] === 'one_off' ? 'one_off' : 'monthly', 'bill_to_party_id' => $payerFor($bt)];
    }
    return $out;
}

/**
 * Open a billing account.
 * $d: unit_id, occupancy_type, start_date, end_date?, rent_amount, billing_day, due_day, deposit_amount, escalation_pct?, escalation_every_months?, notice_period_days, template_id?, notes
 * $parties: [['party_id'=>, 'role'=>'tenant', 'is_primary'=>1], ...]
 * $charges: rows like lease_default_charges()
 */
function lease_create(array $d, array $parties, array $charges): array {
    $unit = one("SELECT * FROM prpt_units WHERE id = ? AND deleted_at IS NULL", [(int)$d['unit_id']]);
    if (!$unit) throw new ValidationError(['unit_id' => 'Choose a unit.']);
    if ($existing = unit_active_lease((int)$unit['id'])) throw new ValidationError(['unit_id' => 'Unit already has an active account (' . $existing['lease_no'] . '). End it first.']);
    if (!$parties) throw new ValidationError(['parties' => 'Add at least one tenant / owner.']);
    foreach ($charges as $i => $c) if (empty($c['bill_to_party_id'])) throw new ValidationError(['charges' => 'Every bill needs a "bill to" person (row ' . ($i + 1) . ').']);

    return tx(function () use ($d, $parties, $charges, $unit) {
        $id = insert('bln_leases', [
            'uuid' => uuid4(), 'lease_no' => next_no('lease'), 'property_id' => (int)$unit['property_id'], 'unit_id' => (int)$unit['id'],
            'occupancy_type' => $d['occupancy_type'], 'start_date' => $d['start_date'], 'end_date' => nullable($d['end_date'] ?? null),
            'move_in_date' => nullable($d['move_in_date'] ?? null) ?? $d['start_date'], 'rent_amount' => (float)($d['rent_amount'] ?? 0),
            'billing_day' => (int)($d['billing_day'] ?? 1), 'due_day' => (int)($d['due_day'] ?? 5),
            'escalation_pct' => nullable($d['escalation_pct'] ?? null), 'escalation_every_months' => nullable($d['escalation_every_months'] ?? null),
            'next_escalation_date' => !empty($d['escalation_every_months']) ? date('Y-m-d', strtotime($d['start_date'] . ' +' . (int)$d['escalation_every_months'] . ' months')) : null,
            'notice_period_days' => (int)($d['notice_period_days'] ?? 30), 'template_id' => nullable($d['template_id'] ?? null),
            'auto_invoice' => (int)($d['auto_invoice'] ?? 1), 'notes' => nullable($d['notes'] ?? null), 'created_by' => staff_id(), 'status' => 'active',
        ]);
        $seen = [];
        foreach ($parties as $p) {
            $k = $p['party_id'] . ':' . $p['role']; if (isset($seen[$k])) continue; $seen[$k] = 1;
            insert('bln_lease_parties', ['lease_id' => $id, 'party_id' => (int)$p['party_id'], 'role' => $p['role'], 'is_primary' => (int)!empty($p['is_primary'])]);
            if (in_array($p['role'], ['tenant', 'co_tenant'], true)) q("UPDATE prpt_parties SET is_tenant = 1 WHERE id = ?", [$p['party_id']]);
            portal_ensure_account((int)$p['party_id']);
        }
        // owners are always attached to the account so they see it in the portal
        foreach (unit_owners((int)$unit['id']) as $o) {
            if (!isset($seen[$o['party_id'] . ':owner'])) { insert('bln_lease_parties', ['lease_id' => $id, 'party_id' => $o['party_id'], 'role' => 'owner']); $seen[$o['party_id'] . ':owner'] = 1; }
        }
        foreach ($charges as $c) {
            insert('bln_lease_charges', [
                'lease_id' => $id, 'unit_id' => (int)$unit['id'], 'bill_type_id' => (int)$c['bill_type_id'], 'billing_method' => $c['billing_method'] ?? 'fixed',
                'amount' => nullable($c['amount'] ?? null), 'unit_rate' => nullable($c['unit_rate'] ?? null), 'frequency' => $c['frequency'] ?? 'monthly',
                'bill_to_party_id' => (int)$c['bill_to_party_id'], 'split_pct' => (float)($c['split_pct'] ?? 100), 'start_date' => $d['start_date'], 'is_active' => 1,
            ]);
        }
        $status = match ($d['occupancy_type']) { 'owner_occupied' => 'owner_occupied', 'owner_vacant' => 'vacant', 'short_stay' => 'occupied', default => 'occupied' };
        set_unit_status((int)$unit['id'], $status, 'Account opened');

        // deposit → held deposit record + one-off deposit invoice to the primary tenant
        $deposit = (float)($d['deposit_amount'] ?? 0);
        $primary = null; foreach ($parties as $p) if (in_array($p['role'], ['tenant', 'co_tenant', 'payer'], true) && ($primary === null || !empty($p['is_primary']))) $primary = (int)$p['party_id'];
        if ($deposit > 0 && $primary) {
            $bt = bill_type_by_code('DEP_RENT');
            $inv = invoice_create(['lease_id' => $id, 'party_id' => $primary, 'unit_id' => (int)$unit['id'], 'property_id' => (int)$unit['property_id'],
                'issue_date' => date('Y-m-d'), 'due_date' => $d['start_date'], 'source' => 'deposit', 'notes' => 'Security deposit'],
                [['bill_type_id' => (int)$bt['id'], 'description' => 'Rent deposit — ' . $unit['unit_no'], 'quantity' => 1, 'unit_price' => $deposit]]);
            insert('bln_deposits', ['lease_id' => $id, 'party_id' => $primary, 'bill_type_id' => (int)$bt['id'], 'amount_required' => $deposit, 'invoice_id' => $inv['id'], 'status' => 'pending']);
        }
        $lease = one("SELECT * FROM bln_leases WHERE id = ?", [$id]);
        if (in_array($d['occupancy_type'], ['tenant_managed', 'company_let', 'tenant_owner_managed'], true)) lease_generate_agreement($id);
        audit('billing', 'create', 'lease', $id, "Opened account {$lease['lease_no']} for unit {$unit['unit_no']}");
        foreach ($parties as $p) portal_notify((int)$p['party_id'], 'lease', 'Your account for unit ' . $unit['unit_no'] . ' is open', 'Account ' . $lease['lease_no'] . ' starts ' . fdate($d['start_date']) . '.', 'fa-file-signature', portal_page('unit', ['id' => $unit['uuid']]));
        return $lease;
    });
}

function lease_end(int $leaseId, string $endDate, string $reason, string $status = 'ended'): void {
    tx(function () use ($leaseId, $endDate, $reason, $status) {
        $l = one("SELECT * FROM bln_leases WHERE id = ?", [$leaseId]);
        update('bln_leases', ['status' => $status, 'end_date' => $endDate, 'move_out_date' => $endDate, 'termination_reason' => $reason], 'id = :id', ['id' => $leaseId]);
        q("UPDATE bln_lease_charges SET is_active = 0, end_date = ? WHERE lease_id = ?", [$endDate, $leaseId]);
        set_unit_status((int)$l['unit_id'], 'vacant', 'Account ended: ' . $reason);
        audit('billing', 'end', 'lease', $leaseId, "Ended {$l['lease_no']}: $reason");
    });
}

/* ------------------------------------------------------------------ invoices */
/** Create an invoice with lines. $h: party_id, property_id, unit_id?, lease_id?, issue_date, due_date, period_month?, source, notes */
function invoice_create(array $h, array $lines, bool $applyCredits = true): array {
    if (!$lines) throw new ValidationError(['lines' => 'Add at least one line.']);
    return tx(function () use ($h, $lines, $applyCredits) {
        $id = insert('bln_invoices', [
            'uuid' => uuid4(), 'invoice_no' => next_no('invoice'), 'lease_id' => nullable($h['lease_id'] ?? null), 'property_id' => (int)$h['property_id'],
            'unit_id' => nullable($h['unit_id'] ?? null), 'party_id' => (int)$h['party_id'], 'booking_id' => nullable($h['booking_id'] ?? null), 'deal_id' => nullable($h['deal_id'] ?? null),
            'period_month' => nullable($h['period_month'] ?? null), 'period_start' => nullable($h['period_start'] ?? null), 'period_end' => nullable($h['period_end'] ?? null),
            'issue_date' => $h['issue_date'] ?? date('Y-m-d'), 'due_date' => $h['due_date'] ?? date('Y-m-d', strtotime('+5 days')),
            'status' => $h['status'] ?? 'issued', 'source' => $h['source'] ?? 'manual', 'run_id' => nullable($h['run_id'] ?? null),
            'notes' => nullable($h['notes'] ?? null), 'created_by' => staff_id(),
        ]);
        foreach (array_values($lines) as $i => $ln) invoice_add_line($id, $ln, $i);
        $inv = invoice_recalc($id);
        invoice_event($id, 'created', 'Invoice ' . $inv['invoice_no'] . ' raised (' . str_replace('_', ' ', $h['source'] ?? 'manual') . ')', (float)$inv['total'], (float)$inv['balance']);
        if ($applyCredits && $inv['status'] !== 'draft') apply_open_credits($id);
        return one("SELECT * FROM bln_invoices WHERE id = ?", [$id]);
    });
}
function invoice_add_line(int $invoiceId, array $ln, int $sort = 0): int {
    $bt = bill_type((int)$ln['bill_type_id']);
    if (!$bt) throw new ValidationError(['lines' => 'Unknown bill type.']);
    $qty = (float)($ln['quantity'] ?? 1); $price = (float)$ln['unit_price']; $amount = round2($qty * $price);
    $tax = 0.0;
    if ($bt['tax_rate_id']) $tax = round2($amount * (float)val("SELECT rate_pct FROM cfg_tax_rates WHERE id = ? AND is_active = 1", [$bt['tax_rate_id']]) / 100);
    return insert('bln_invoice_lines', ['invoice_id' => $invoiceId, 'bill_type_id' => (int)$bt['id'], 'lease_charge_id' => nullable($ln['lease_charge_id'] ?? null),
        'description' => mb_substr((string)($ln['description'] ?? $bt['name']), 0, 255), 'quantity' => $qty, 'unit_price' => $price, 'amount' => $amount,
        'tax_amount' => $tax, 'meter_reading_id' => nullable($ln['meter_reading_id'] ?? null), 'sort_order' => $sort]);
}
/** Recompute totals & status from lines. */
function invoice_recalc(int $id): array {
    $t = one("SELECT COALESCE(SUM(amount),0) sub, COALESCE(SUM(tax_amount),0) tax, COALESCE(SUM(amount_paid),0) paid FROM bln_invoice_lines WHERE invoice_id = ?", [$id]);
    $credit = (float)val("SELECT COALESCE(SUM(amount),0) FROM bln_credit_notes WHERE invoice_id = ?", [$id]);
    $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [$id]);
    $total = round2($t['sub'] + $t['tax']); $paid = round2($t['paid']); $balance = round2(max(0, $total - $paid - $credit));
    $status = $inv['status'];
    if (!in_array($status, ['void', 'written_off', 'draft'], true)) {
        $status = $balance <= 0.004 ? 'paid' : ($paid > 0 ? 'partially_paid' : ($inv['due_date'] < date('Y-m-d') ? 'overdue' : 'issued'));
    }
    update('bln_invoices', ['subtotal' => round2($t['sub']), 'tax_total' => round2($t['tax']), 'total' => $total, 'amount_paid' => $paid, 'balance' => $status === 'void' ? 0 : $balance, 'status' => $status], 'id = :id', ['id' => $id]);
    return one("SELECT * FROM bln_invoices WHERE id = ?", [$id]);
}
function invoice_event(int $invoiceId, string $event, string $description, ?float $amount = null, ?float $balanceAfter = null, ?array $changes = null): void {
    [$type, $aid, $name] = audit_actor();
    insert('bln_invoice_events', ['invoice_id' => $invoiceId, 'event' => $event, 'description' => mb_substr($description, 0, 255), 'amount' => $amount,
        'balance_after' => $balanceAfter, 'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null, 'actor_type' => $type, 'actor_id' => $aid, 'actor_name' => $name]);
}

/** Manual edit: header fields + full line replacement (only unpaid lines may change). Logged with a diff. */
function invoice_update(int $id, array $h, array $lines): array {
    return tx(function () use ($id, $h, $lines) {
        $old = one("SELECT * FROM bln_invoices WHERE id = ? FOR UPDATE", [$id]);
        if (!$old || in_array($old['status'], ['void', 'written_off'], true)) throw new RuntimeException('This invoice can no longer be edited.');
        $oldLines = all("SELECT id, bill_type_id, description, quantity, unit_price, amount, amount_paid FROM bln_invoice_lines WHERE invoice_id = ?", [$id]);
        $keep = []; $changes = [];
        foreach (['issue_date', 'due_date', 'notes', 'party_id'] as $f) if (array_key_exists($f, $h) && (string)$old[$f] !== (string)$h[$f]) $changes[$f] = ['from' => $old[$f], 'to' => $h[$f]];
        update('bln_invoices', array_intersect_key($h, array_flip(['issue_date', 'due_date', 'notes', 'party_id'])), 'id = :id', ['id' => $id]);
        foreach ($lines as $i => $ln) {
            if (!empty($ln['id'])) {
                $ol = null; foreach ($oldLines as $x) if ((int)$x['id'] === (int)$ln['id']) $ol = $x;
                if (!$ol) continue; $keep[] = (int)$ol['id'];
                $amount = round2((float)$ln['quantity'] * (float)$ln['unit_price']);
                if ($amount < (float)$ol['amount_paid']) throw new ValidationError(['lines' => "Line \"{$ol['description']}\" already has " . money($ol['amount_paid']) . ' paid — amount cannot go below that.']);
                if ((float)$ol['amount'] !== $amount || $ol['description'] !== $ln['description'] || (int)$ol['bill_type_id'] !== (int)$ln['bill_type_id']) {
                    $changes['line_' . $ol['id']] = ['from' => $ol['description'] . ' ' . money($ol['amount']), 'to' => $ln['description'] . ' ' . money($amount)];
                    $bt = bill_type((int)$ln['bill_type_id']);
                    $tax = $bt['tax_rate_id'] ? round2($amount * (float)val("SELECT rate_pct FROM cfg_tax_rates WHERE id = ?", [$bt['tax_rate_id']]) / 100) : 0;
                    update('bln_invoice_lines', ['bill_type_id' => (int)$ln['bill_type_id'], 'description' => $ln['description'], 'quantity' => (float)$ln['quantity'], 'unit_price' => (float)$ln['unit_price'], 'amount' => $amount, 'tax_amount' => $tax, 'sort_order' => $i], 'id = :id', ['id' => $ol['id']]);
                }
            } else {
                $nid = invoice_add_line($id, $ln, $i); $keep[] = $nid;
                $changes['added_' . $nid] = ['from' => null, 'to' => $ln['description'] . ' ' . money((float)$ln['quantity'] * (float)$ln['unit_price'])];
            }
        }
        foreach ($oldLines as $ol) if (!in_array((int)$ol['id'], $keep, true)) {
            if ((float)$ol['amount_paid'] > 0) throw new ValidationError(['lines' => "Can't remove \"{$ol['description']}\" — it has payments. Reverse the allocation first."]);
            q("DELETE FROM bln_invoice_lines WHERE id = ?", [$ol['id']]);
            $changes['removed_' . $ol['id']] = ['from' => $ol['description'] . ' ' . money($ol['amount']), 'to' => null];
        }
        $inv = invoice_recalc($id);
        if ($changes) {
            invoice_event($id, 'edited', 'Invoice edited (' . count($changes) . ' change' . (count($changes) > 1 ? 's' : '') . ')', (float)$inv['total'], (float)$inv['balance'], $changes);
            audit('billing', 'update', 'invoice', $id, 'Edited ' . $inv['invoice_no'], ['total' => $old['total']], ['total' => $inv['total']]);
        }
        return $inv;
    });
}
function invoice_void(int $id, string $reason): void {
    tx(function () use ($id, $reason) {
        $inv = one("SELECT * FROM bln_invoices WHERE id = ? FOR UPDATE", [$id]);
        if ((float)$inv['amount_paid'] > 0) throw new RuntimeException('Reverse the payments allocated to this invoice before voiding it.');
        update('bln_invoices', ['status' => 'void', 'balance' => 0, 'void_reason' => $reason], 'id = :id', ['id' => $id]);
        q("UPDATE prpt_meter_readings SET status = 'approved', invoice_line_id = NULL WHERE invoice_line_id IN (SELECT id FROM bln_invoice_lines WHERE invoice_id = ?)", [$id]);
        invoice_event($id, 'voided', 'Voided: ' . $reason, (float)$inv['total'], 0);
        audit('billing', 'void', 'invoice', $id, "Voided {$inv['invoice_no']}: $reason");
    });
}
function invoice_credit_note(int $id, float $amount, string $reason): void {
    tx(function () use ($id, $amount, $reason) {
        $inv = one("SELECT * FROM bln_invoices WHERE id = ? FOR UPDATE", [$id]);
        if ($amount <= 0 || $amount > (float)$inv['balance'] + 0.001) throw new ValidationError(['amount' => 'Credit must be between 0 and the balance (' . money($inv['balance']) . ').']);
        insert('bln_credit_notes', ['credit_no' => next_no('credit_note'), 'invoice_id' => $id, 'amount' => $amount, 'reason' => $reason, 'created_by' => staff_id()]);
        $inv = invoice_recalc($id);
        invoice_event($id, 'credit_applied', 'Credit note: ' . $reason, -$amount, (float)$inv['balance']);
        audit('billing', 'credit_note', 'invoice', $id, money($amount) . " credit on {$inv['invoice_no']}");
    });
}
function invoice_lines_with_balance(int $invoiceId): array {
    return all("SELECT l.*, bt.name AS bill_type, bt.code AS bill_code, bt.color_hex, (l.amount + l.tax_amount - l.amount_paid) AS balance
                FROM bln_invoice_lines l JOIN cfg_bill_types bt ON bt.id = l.bill_type_id WHERE l.invoice_id = ? ORDER BY l.sort_order, l.id", [$invoiceId]);
}

/* ------------------------------------------------------------------ billing runs */
function period_bounds(string $period): array { $s = $period . '-01'; return [$s, date('Y-m-t', strtotime($s))]; }
function charge_due_in_period(array $c, string $period): bool {
    if ($c['last_billed_period'] === $period) return false;
    if ($c['frequency'] === 'one_off') return $c['last_billed_period'] === null;
    $months = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12][$c['frequency']] ?? 1;
    if ($months === 1) return true;
    $start = new DateTime(substr($c['start_date'], 0, 7) . '-01'); $p = new DateTime($period . '-01');
    $diff = ($p->format('Y') - $start->format('Y')) * 12 + ($p->format('n') - $start->format('n'));
    return $diff >= 0 && $diff % $months === 0;
}
/** Metered tariff (tiers by property → global → flat rate) */
function metered_amount(int $billTypeId, int $propertyId, float $units, ?float $flatRate): array {
    $tiers = all("SELECT * FROM cfg_tariff_tiers WHERE bill_type_id = ? AND property_id = ? ORDER BY from_units", [$billTypeId, $propertyId])
          ?: all("SELECT * FROM cfg_tariff_tiers WHERE bill_type_id = ? AND property_id IS NULL ORDER BY from_units", [$billTypeId]);
    $bt = bill_type($billTypeId);
    if (!$tiers) { $rate = $flatRate ?? (float)$bt['default_unit_rate']; $amt = round2($units * $rate); }
    else { $amt = 0; foreach ($tiers as $t) { $lo = (float)$t['from_units']; $hi = $t['to_units'] === null ? INF : (float)$t['to_units']; if ($units > $lo) $amt += (min($units, $hi) - $lo) * (float)$t['rate']; } $amt = round2($amt); $rate = $units > 0 ? $amt / $units : 0; }
    if ($bt['minimum_charge'] !== null && $amt < (float)$bt['minimum_charge']) $amt = (float)$bt['minimum_charge'];
    return [$amt, $rate];
}

/**
 * Generate invoices for a period. Groups every due charge by (account, bill-to party)
 * into one invoice, adds metered lines from approved unbilled readings, prorates the first month.
 * $preview = true returns what *would* be invoiced without writing.
 */
function run_billing(string $period, ?int $propertyId = null, array $billTypeIds = [], bool $preview = false, string $trigger = 'manual'): array {
    [$pStart, $pEnd] = period_bounds($period);
    $where = "l.status IN ('active','notice') AND l.auto_invoice = 1 AND l.start_date <= :pe AND (l.end_date IS NULL OR l.end_date >= :ps)";
    $p = ['pe' => $pEnd, 'ps' => $pStart];
    if ($propertyId) { $where .= ' AND l.property_id = :pid'; $p['pid'] = $propertyId; }
    $where .= ' AND ' . scope_sql('l.property_id');
    $leases = all("SELECT l.*, u.unit_no, u.uuid AS unit_uuid, pr.due_day AS prop_due_day FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE $where ORDER BY l.property_id, u.unit_no", $p);
    $plan = [];
    $prorate = (bool)setting('billing_prorate_first_month', true);
    foreach ($leases as $l) {
        $charges = all("SELECT c.*, bt.name AS bt_name, bt.code AS bt_code, bt.unit_label FROM bln_lease_charges c JOIN cfg_bill_types bt ON bt.id = c.bill_type_id WHERE c.lease_id = ? AND c.is_active = 1 AND c.start_date <= ? AND (c.end_date IS NULL OR c.end_date >= ?)", [$l['id'], $pEnd, $pStart]);
        foreach ($charges as $c) {
            if ($billTypeIds && !in_array((int)$c['bill_type_id'], $billTypeIds, true)) continue;
            $payer = (int)$c['bill_to_party_id'];
            if ($c['billing_method'] === 'metered') {
                $readings = all("SELECT r.*, m.serial_no FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id
                                 WHERE m.unit_id = ? AND m.bill_type_id = ? AND r.status = 'approved' AND r.invoice_line_id IS NULL AND r.reading_date <= ?", [$c['unit_id'] ?? $l['unit_id'], $c['bill_type_id'], $pEnd]);
                foreach ($readings as $r) {
                    [$amt, $rate] = metered_amount((int)$c['bill_type_id'], (int)$l['property_id'], (float)$r['consumption'], $c['unit_rate'] !== null ? (float)$c['unit_rate'] : null);
                    $amt = round2($amt * (float)$c['split_pct'] / 100);
                    $plan[$l['id'] . ':' . $payer]['lines'][] = ['bill_type_id' => (int)$c['bill_type_id'], 'lease_charge_id' => (int)$c['id'], 'meter_reading_id' => (int)$r['id'],
                        'description' => sprintf('%s — %s %s (%s → %s, meter %s)', $c['bt_name'], num($r['consumption'], 2), $c['unit_label'] ?: 'units', num($r['previous_reading'], 1), num($r['current_reading'], 1), $r['serial_no']),
                        'quantity' => (float)$r['consumption'], 'unit_price' => $r['consumption'] > 0 ? round($amt / (float)$r['consumption'], 4) : $amt];
                    if ((float)$r['consumption'] == 0.0) { $last = array_key_last($plan[$l['id'] . ':' . $payer]['lines']); $plan[$l['id'] . ':' . $payer]['lines'][$last]['quantity'] = 1; }
                }
            } else {
                if (!charge_due_in_period($c, $period)) continue;
                $amt = (float)$c['amount'] * (float)$c['split_pct'] / 100;
                $desc = $c['bt_name'] . ' — ' . month_label($period);
                if ($prorate && $c['frequency'] === 'monthly' && substr($l['start_date'], 0, 7) === $period && (int)substr($l['start_date'], 8, 2) > 1) {
                    $days = (int)date('t', strtotime($pStart)); $occupied = $days - (int)substr($l['start_date'], 8, 2) + 1;
                    $amt = $amt * $occupied / $days; $desc .= " (prorated $occupied/$days days)";
                }
                if ($amt <= 0) continue;
                $plan[$l['id'] . ':' . $payer]['lines'][] = ['bill_type_id' => (int)$c['bill_type_id'], 'lease_charge_id' => (int)$c['id'], 'description' => $desc, 'quantity' => 1, 'unit_price' => round2($amt)];
            }
            $plan[$l['id'] . ':' . $payer]['lease'] = $l; $plan[$l['id'] . ':' . $payer]['party_id'] = $payer;
        }
    }
    $plan = array_filter($plan, fn($x) => !empty($x['lines']));
    if ($preview) {
        return array_values(array_map(function ($x) {
            $party = one("SELECT display_name FROM prpt_parties WHERE id = ?", [$x['party_id']]);
            return ['lease_no' => $x['lease']['lease_no'], 'unit_no' => $x['lease']['unit_no'], 'party' => $party['display_name'] ?? '?',
                    'lines' => $x['lines'], 'total' => round2(array_sum(array_map(fn($l) => $l['quantity'] * $l['unit_price'], $x['lines'])))];
        }, $plan));
    }
    return tx(function () use ($plan, $period, $propertyId, $billTypeIds, $trigger, $pStart, $pEnd) {
        $runId = insert('bln_billing_runs', ['run_no' => next_no('run'), 'period_month' => $period, 'property_id' => $propertyId,
            'bill_types' => $billTypeIds ? implode(',', $billTypeIds) : null, 'trigger_type' => $trigger, 'created_by' => staff_id()]);
        $count = 0; $total = 0.0; $invoices = [];
        foreach ($plan as $x) {
            $l = $x['lease'];
            $due = sprintf('%s-%02d', $period, min((int)$l['due_day'] ?: (int)$l['prop_due_day'], (int)date('t', strtotime($pStart))));
            $inv = invoice_create(['lease_id' => (int)$l['id'], 'property_id' => (int)$l['property_id'], 'unit_id' => (int)$l['unit_id'], 'party_id' => $x['party_id'],
                'period_month' => $period, 'period_start' => $pStart, 'period_end' => $pEnd, 'issue_date' => date('Y-m-d'), 'due_date' => max($due, date('Y-m-d')),
                'source' => 'auto', 'run_id' => $runId], $x['lines']);
            foreach (all("SELECT id, meter_reading_id, lease_charge_id FROM bln_invoice_lines WHERE invoice_id = ?", [$inv['id']]) as $ln) {
                if ($ln['meter_reading_id']) q("UPDATE prpt_meter_readings SET status = 'billed', invoice_line_id = ? WHERE id = ?", [$ln['id'], $ln['meter_reading_id']]);
                if ($ln['lease_charge_id']) q("UPDATE bln_lease_charges SET last_billed_period = ? WHERE id = ? AND billing_method <> 'metered'", [$period, $ln['lease_charge_id']]);
            }
            q("UPDATE bln_leases SET last_invoiced_period = ? WHERE id = ?", [$period, $l['id']]);
            $count++; $total += (float)$inv['total']; $invoices[] = $inv['id'];
        }
        update('bln_billing_runs', ['invoices_count' => $count, 'total_amount' => round2($total)], 'id = :id', ['id' => $runId]);
        audit('billing', 'run', 'billing_run', $runId, "Billing run $period: $count invoices, " . money($total));
        return ['run_id' => $runId, 'invoices' => $count, 'total' => round2($total), 'invoice_ids' => $invoices];
    });
}

/** Send (email + portal) an invoice to its bill-to party */
function invoice_send(int $id): void {
    $inv = one("SELECT i.*, p.display_name, p.email, p.phone, u.unit_no, u.uuid AS unit_uuid, pr.name AS property_name FROM bln_invoices i JOIN prpt_parties p ON p.id = i.party_id
                LEFT JOIN prpt_units u ON u.id = i.unit_id JOIN prpt_properties pr ON pr.id = i.property_id WHERE i.id = ?", [$id]);
    $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$inv['party_id']]);
    $lines = invoice_lines_with_balance($id);
    $rows = ''; foreach ($lines as $l) $rows .= '<tr><td style="padding:6px 0">' . e($l['description']) . '</td><td style="text-align:right">' . money($l['amount'] + $l['tax_amount']) . '</td></tr>';
    send_templated('invoice_issued', $party, [
        'invoice_no' => $inv['invoice_no'], 'amount' => $inv['total'], 'balance' => $inv['balance'], 'due_date' => fdate($inv['due_date']),
        'unit_no' => $inv['unit_no'], 'property_name' => $inv['property_name'], 'period' => $inv['period_month'] ? month_label($inv['period_month']) : '',
        'lines_table' => '<table width="100%" style="border-collapse:collapse;font-size:14px">' . $rows . '</table>', 'portal_link' => rtrim((string)config('app.portal_url'), '/') . '/?p=invoice&id=' . $inv['uuid'],
        'paybill' => setting('paybill_number', '—'), 'account_ref' => $inv['unit_no'],
    ], null, 'invoice', $id);
    portal_notify((int)$inv['party_id'], 'invoice', 'New invoice ' . $inv['invoice_no'], money($inv['total']) . ' due ' . fdate($inv['due_date']), 'fa-file-invoice', portal_page('invoice', ['id' => $inv['uuid']]));
    q("UPDATE bln_invoices SET sent_at = NOW() WHERE id = ?", [$id]);
    invoice_event($id, 'sent', 'Sent to ' . ($party['email'] ?: $party['phone'] ?: 'portal'));
}

/** Late-payment penalties (cron) */
function run_penalties(): int {
    $n = 0;
    foreach (all("SELECT * FROM cfg_penalty_rules WHERE is_active = 1") as $rule) {
        $bt = bill_type_by_code('PENALTY'); if (!$bt) break;
        $sql = "SELECT i.* FROM bln_invoices i WHERE i.status IN ('issued','partially_paid','overdue') AND i.balance > 0 AND i.source <> 'penalty' AND DATE_ADD(i.due_date, INTERVAL :g DAY) < CURDATE()
                AND NOT EXISTS (SELECT 1 FROM bln_invoice_events e WHERE e.invoice_id = i.id AND e.event = 'penalty')";
        $p = ['g' => (int)$rule['grace_days']];
        if ($rule['property_id']) { $sql .= ' AND i.property_id = :pid'; $p['pid'] = $rule['property_id']; }
        if ($rule['applies_to_bill_type_id']) { $sql .= ' AND EXISTS (SELECT 1 FROM bln_invoice_lines l WHERE l.invoice_id = i.id AND l.bill_type_id = :bt)'; $p['bt'] = $rule['applies_to_bill_type_id']; }
        foreach (all($sql, $p) as $inv) {
            $amt = $rule['penalty_type'] === 'percentage' ? round2((float)$inv['balance'] * (float)$rule['penalty_value'] / 100) : (float)$rule['penalty_value'];
            if ($rule['max_penalty'] !== null) $amt = min($amt, (float)$rule['max_penalty']);
            if ($amt <= 0) continue;
            $pen = invoice_create(['lease_id' => $inv['lease_id'], 'property_id' => $inv['property_id'], 'unit_id' => $inv['unit_id'], 'party_id' => $inv['party_id'],
                'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+7 days')), 'source' => 'penalty', 'notes' => 'Late payment on ' . $inv['invoice_no']],
                [['bill_type_id' => (int)$bt['id'], 'description' => 'Late payment penalty on ' . $inv['invoice_no'] . ' (' . $rule['name'] . ')', 'quantity' => 1, 'unit_price' => $amt]]);
            invoice_event((int)$inv['id'], 'penalty', 'Penalty ' . $pen['invoice_no'] . ' raised', $amt);
            $n++;
        }
    }
    return $n;
}
/** Mark overdue (cron) */
function refresh_overdue(): int { return q("UPDATE bln_invoices SET status = 'overdue' WHERE status IN ('issued','partially_paid') AND balance > 0 AND due_date < CURDATE() AND amount_paid = 0")->rowCount(); }

/** Arrears per invoice line with filters: property_id, block_id, unit_id, bill_type_id, party_id, from, to */
function arrears_query(array $f, bool $grouped = false): array {
    $w = ["i.status NOT IN ('void','written_off','draft')", '(l.amount + l.tax_amount - l.amount_paid) > 0.004', scope_sql('i.property_id')]; $p = [];
    foreach (['property_id' => 'i.property_id', 'unit_id' => 'i.unit_id', 'bill_type_id' => 'l.bill_type_id', 'party_id' => 'i.party_id', 'lease_id' => 'i.lease_id'] as $k => $c)
        if (!empty($f[$k])) { $w[] = "$c = :$k"; $p[$k] = (int)$f[$k]; }
    if (!empty($f['block_id'])) { $w[] = 'u.block_id = :block'; $p['block'] = (int)$f['block_id']; }
    if (!empty($f['from'])) { $w[] = 'i.issue_date >= :from'; $p['from'] = $f['from']; }
    if (!empty($f['to'])) { $w[] = 'i.issue_date <= :to'; $p['to'] = $f['to']; }
    if (!empty($f['overdue_only'])) $w[] = 'i.due_date < CURDATE()';
    $sql = "FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id JOIN cfg_bill_types bt ON bt.id = l.bill_type_id JOIN prpt_parties pt ON pt.id = i.party_id
            JOIN prpt_properties pr ON pr.id = i.property_id LEFT JOIN prpt_units u ON u.id = i.unit_id LEFT JOIN prpt_blocks b ON b.id = u.block_id WHERE " . implode(' AND ', $w);
    if ($grouped) return all("SELECT i.party_id, pt.display_name, pt.phone, u.unit_no, b.code AS block_code, pr.name AS property_name, i.unit_id,
            SUM(l.amount + l.tax_amount) invoiced, SUM(l.amount_paid) paid, SUM(l.amount + l.tax_amount - l.amount_paid) arrears, MIN(i.due_date) oldest_due, COUNT(DISTINCT i.id) invoices
            $sql GROUP BY i.party_id, i.unit_id ORDER BY arrears DESC", $p);
    return all("SELECT l.id AS line_id, l.description, l.amount + l.tax_amount AS line_total, l.amount_paid, (l.amount + l.tax_amount - l.amount_paid) AS arrears,
            bt.name AS bill_type, bt.color_hex, i.id AS invoice_id, i.uuid AS invoice_uuid, i.invoice_no, i.issue_date, i.due_date, i.status, i.party_id, pt.display_name, pt.phone,
            u.unit_no, b.code AS block_code, pr.name AS property_name, DATEDIFF(CURDATE(), i.due_date) AS days_overdue
            $sql ORDER BY i.due_date, i.id LIMIT 2000", $p);
}
