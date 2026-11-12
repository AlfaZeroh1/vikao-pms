<?php
/** Document generation: lease agreements (and any template) from prpt_doc_templates. */

function lease_vars(int $leaseId): array {
    $l = one("SELECT l.*, u.unit_no, u.unit_type, u.bedrooms, u.size_sqm, u.usage_class, pr.name AS property_name, pr.address, pr.area, pr.county, pr.lr_number, b.name AS block_name
              FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id LEFT JOIN prpt_blocks b ON b.id = u.block_id WHERE l.id = ?", [$leaseId]);
    $parties = all("SELECT p.*, lp.role, lp.is_primary FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = ? ORDER BY lp.is_primary DESC", [$leaseId]);
    $tenants = array_values(array_filter($parties, fn($p) => in_array($p['role'], ['tenant', 'co_tenant'], true)));
    $owners = array_values(array_filter($parties, fn($p) => $p['role'] === 'owner'));
    $charges = all("SELECT c.*, bt.name, bt.unit_label, p.display_name AS payer FROM bln_lease_charges c JOIN cfg_bill_types bt ON bt.id = c.bill_type_id JOIN prpt_parties p ON p.id = c.bill_to_party_id WHERE c.lease_id = ? AND c.is_active = 1", [$leaseId]);
    $rows = '';
    foreach ($charges as $c) $rows .= '<tr><td>' . e($c['name']) . '</td><td>' . ($c['billing_method'] === 'metered' ? 'Metered @ ' . money($c['unit_rate']) . ' / ' . e($c['unit_label'] ?: 'unit') : money($c['amount'])) . '</td><td>' . e(ucfirst($c['frequency'])) . '</td><td>' . e($c['payer']) . '</td></tr>';
    $deposit = (float)val("SELECT COALESCE(SUM(amount_required),0) FROM bln_deposits WHERE lease_id = ?", [$leaseId]);
    $names = fn($list) => implode(' & ', array_map(fn($p) => $p['display_name'], $list)) ?: '—';
    $ids = fn($list) => implode(', ', array_map(fn($p) => ($p['id_number'] ?: '—'), $list));
    return [
        'lease_no' => $l['lease_no'], 'date' => date('j F Y'), 'company_name' => setting('company_name', config('app.company')), 'company_address' => setting('company_address', ''),
        'tenant_names' => $names($tenants), 'tenant_ids' => $ids($tenants), 'tenant_phone' => $tenants[0]['phone'] ?? '', 'tenant_email' => $tenants[0]['email'] ?? '',
        'owner_names' => $names($owners), 'property_name' => $l['property_name'], 'property_address' => trim(($l['address'] ?? '') . ', ' . ($l['area'] ?? '') . ', ' . ($l['county'] ?? ''), ', '),
        'lr_number' => $l['lr_number'] ?: '—', 'unit_no' => $l['unit_no'], 'block' => $l['block_name'] ?: '—', 'unit_type' => ucwords(str_replace('_', ' ', $l['unit_type'])),
        'bedrooms' => $l['bedrooms'] ?? '—', 'usage' => ucfirst($l['usage_class']), 'start_date' => fdate($l['start_date'], 'j F Y'), 'end_date' => $l['end_date'] ? fdate($l['end_date'], 'j F Y') : 'until terminated by notice',
        'term' => $l['end_date'] ? round((strtotime($l['end_date']) - strtotime($l['start_date'])) / (86400 * 30.44)) . ' months' : 'periodic (month to month)',
        'rent_amount' => money($l['rent_amount']), 'rent_words' => number_words((int)$l['rent_amount']) . ' Kenya Shillings', 'deposit' => money($deposit),
        'billing_day' => ordinal((int)$l['billing_day']), 'due_day' => ordinal((int)$l['due_day']), 'notice_days' => $l['notice_period_days'],
        'escalation' => $l['escalation_pct'] ? num($l['escalation_pct'], 1) . '% every ' . $l['escalation_every_months'] . ' months' : 'none',
        'charges_table' => '<table class="doc-table"><thead><tr><th>Charge</th><th>Amount</th><th>Frequency</th><th>Billed to</th></tr></thead><tbody>' . $rows . '</tbody></table>',
        'paybill' => setting('paybill_number', ''), 'account_ref' => $l['unit_no'],
    ];
}
function ordinal(int $n): string { $s = ['th', 'st', 'nd', 'rd']; $v = $n % 100; return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]); }
function number_words(int $n): string {
    if ($n === 0) return 'zero';
    $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
    $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
    $f = function ($n) use (&$f, $ones, $tens) {
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' and ' . $f($n % 100) : '');
        foreach ([1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $d => $w) if ($n >= $d) return $f(intdiv($n, $d)) . " $w" . ($n % $d ? ($n % $d < 100 ? ' and ' : ', ') . $f($n % $d) : '');
        return '';
    };
    return ucfirst($f($n));
}

/** Generate (or regenerate) the lease agreement document and attach it to the account. */
function lease_generate_agreement(int $leaseId): int {
    $l = one("SELECT l.*, u.usage_class FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id WHERE l.id = ?", [$leaseId]);
    $tpl = $l['template_id'] ? one("SELECT * FROM prpt_doc_templates WHERE id = ?", [$l['template_id']]) : null;
    $tpl = $tpl ?? one("SELECT * FROM prpt_doc_templates WHERE doc_type = 'lease' AND usage_class IN (?, 'any') ORDER BY usage_class = ? DESC, is_default DESC LIMIT 1", [$l['usage_class'], $l['usage_class']]);
    if (!$tpl) return 0;
    $html = render_placeholders($tpl['body_html'], lease_vars($leaseId));
    $tenant = lease_primary_payer($leaseId);
    if ($l['agreement_document_id']) {
        update('prpt_documents', ['body_html' => $html, 'signed_at' => null], 'id = :id', ['id' => $l['agreement_document_id']]);
        return (int)$l['agreement_document_id'];
    }
    $docId = insert('prpt_documents', ['uuid' => uuid4(), 'title' => 'Lease agreement ' . $l['lease_no'], 'doc_type' => 'lease', 'owner_type' => 'lease', 'owner_id' => $leaseId,
        'property_id' => $l['property_id'], 'party_id' => $tenant, 'body_html' => $html, 'visible_in_portal' => 1, 'created_by' => staff_id()]);
    update('bln_leases', ['agreement_document_id' => $docId, 'template_id' => $tpl['id']], 'id = :id', ['id' => $leaseId]);
    return $docId;
}
/** Who may see a document in the portal */
function document_visible_to(array $doc, int $partyId): bool {
    if (!$doc['visible_in_portal']) return false;
    if ((int)$doc['party_id'] === $partyId) return true;
    if ($doc['owner_type'] === 'lease') return (bool)val("SELECT 1 FROM bln_lease_parties WHERE lease_id = ? AND party_id = ?", [$doc['owner_id'], $partyId]);
    if ($doc['owner_type'] === 'party') return (int)$doc['owner_id'] === $partyId;
    if ($doc['owner_type'] === 'unit') return isset(party_owned_units($partyId)[(int)$doc['owner_id']]) || (bool)val("SELECT 1 FROM bln_leases l JOIN bln_lease_parties lp ON lp.lease_id = l.id WHERE l.unit_id = ? AND lp.party_id = ? AND l.status IN ('active','notice')", [$doc['owner_id'], $partyId]);
    if ($doc['owner_type'] === 'property') return (bool)val("SELECT 1 FROM prpt_ownerships WHERE property_id = ? AND party_id = ?", [$doc['owner_id'], $partyId]);
    return false;
}
