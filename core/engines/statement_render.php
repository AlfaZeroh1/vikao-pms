<?php
/**
 * Statement HTML (shared by PMS and the portal). Types:
 *  party    — a payer's account (tenant or owner billed), optionally one unit / lease / bill type
 *  lease    — one billing account
 *  unit     — every bill raised on a house and what was collected
 *  property — per-unit invoiced / collected / arrears + expenses
 *  landlord — owner earnings per property: collected, fees, expenses, remitted vs unremitted
 */
function statement_render(string $type, int $id, array $f): array {
    $from = $f['from'] ?? date('Y-01-01'); $to = $f['to'] ?? date('Y-m-d');
    $btName = !empty($f['bill_type_id']) ? (string)val("SELECT name FROM cfg_bill_types WHERE id = ?", [$f['bill_type_id']]) : 'All bills';
    $period = fdate($from) . ' – ' . fdate($to);
    ob_start();
    switch ($type) {
        case 'lease':
            $l = one("SELECT l.*, u.unit_no, pr.name AS property FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE l.id = ?", [$id]);
            if (!$l) return ['title' => 'Not found', 'html' => '<p>Account not found.</p>'];
            $f['lease_id'] = $id; $payer = (int)lease_primary_payer($id);
            $title = 'Account statement ' . $l['lease_no'];
            echo statement_party_block($payer, $f, $l['lease_no'] . ' · ' . $l['unit_no'] . ' · ' . $l['property'], $btName, $period);
            break;
        case 'party':
            $p = one("SELECT * FROM prpt_parties WHERE id = ?", [$id]);
            if (!$p) return ['title' => 'Not found', 'html' => '<p>Not found.</p>'];
            $title = 'Statement — ' . $p['display_name'];
            $sub = !empty($f['unit_id']) ? 'Unit ' . val("SELECT unit_no FROM prpt_units WHERE id = ?", [$f['unit_id']]) : 'All units';
            echo statement_party_block($id, $f, $sub, $btName, $period);
            break;
        case 'unit':
            $u = one("SELECT u.*, pr.name AS property FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.id = ?", [$id]);
            if (!$u) return ['title' => 'Not found', 'html' => '<p>Not found.</p>'];
            $s = statement_unit($id, $f); $title = 'House statement — ' . $u['unit_no'];
            echo '<div class="grid2"><div><h1>HOUSE STATEMENT</h1><p class="muted">' . e($u['unit_no'] . ' · ' . $u['property']) . '</p></div><div class="right"><p>' . e($period) . '<br>' . e($btName) . '</p></div></div>';
            echo '<table><thead><tr><th>Date</th><th>Reference</th><th>Party</th><th>Details</th><th class="num">Billed</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody><tr><td colspan="6"><i>Opening balance</i></td><td class="num">' . money($s['opening'], false) . '</td></tr>';
            foreach ($s['rows'] as $r) echo '<tr><td>' . fdate($r['d']) . '</td><td>' . e($r['ref']) . '</td><td>' . e($r['who']) . '</td><td class="muted">' . e(excerpt($r['descr'], 90)) . '</td><td class="num">' . ((float)$r['debit'] ? money($r['debit'], false) : '') . '</td><td class="num">' . ((float)$r['credit'] ? money($r['credit'], false) : '') . '</td><td class="num">' . money($r['balance'], false) . '</td></tr>';
            echo '<tr class="tot"><td colspan="6">Closing balance</td><td class="num">' . money($s['closing']) . '</td></tr></tbody></table>';
            break;
        case 'property':
            $pr = one("SELECT * FROM prpt_properties WHERE id = ?", [$id]);
            if (!$pr) return ['title' => 'Not found', 'html' => '<p>Not found.</p>'];
            $s = statement_property($id, $f); $t = $s['totals']; $title = 'Property statement — ' . $pr['name'];
            echo '<div class="grid2"><div><h1>PROPERTY STATEMENT</h1><p class="muted">' . e($pr['name']) . '</p></div><div class="right"><p>' . e($period) . '<br>' . e($btName) . '<br>Occupancy ' . $s['occupancy'] . '%</p></div></div>';
            echo '<div class="kpis"><div class="box">Invoiced<b>' . money($t['invoiced']) . '</b></div><div class="box">Collected<b>' . money($t['collected']) . '</b></div><div class="box">Arrears<b>' . money($t['arrears']) . '</b></div><div class="box">Expenses<b>' . money($t['expenses']) . '</b></div></div>';
            echo '<h2>By bill type</h2><table><thead><tr><th>Bill</th><th class="num">Invoiced</th><th class="num">Paid</th><th class="num">Rate</th></tr></thead><tbody>';
            foreach ($s['by_bill'] as $b) echo '<tr><td>' . e($b['name']) . '</td><td class="num">' . money($b['invoiced'], false) . '</td><td class="num">' . money($b['paid'], false) . '</td><td class="num">' . ((float)$b['invoiced'] > 0 ? round($b['paid'] / $b['invoiced'] * 100) : 0) . '%</td></tr>';
            echo '</tbody></table><h2>By unit</h2><table><thead><tr><th>Unit</th><th>Status</th><th>Tenant</th><th class="num">Invoiced</th><th class="num">Collected</th><th class="num">Arrears</th></tr></thead><tbody>';
            foreach ($s['units'] as $u) echo '<tr><td>' . e(($u['block_code'] ? $u['block_code'] . '-' : '') . $u['unit_no']) . '</td><td>' . e(str_replace('_', ' ', $u['status'])) . '</td><td>' . e($u['tenant'] ?? '') . '</td><td class="num">' . money($u['invoiced'], false) . '</td><td class="num">' . money($u['collected'], false) . '</td><td class="num">' . money($u['arrears'], false) . '</td></tr>';
            echo '<tr class="tot"><td colspan="3">Totals</td><td class="num">' . money($t['invoiced'], false) . '</td><td class="num">' . money($t['collected'], false) . '</td><td class="num">' . money($t['arrears'], false) . '</td></tr></tbody></table>';
            if ($s['expenses']) { echo '<h2>Expenses</h2><table><tbody>'; foreach ($s['expenses'] as $x) echo '<tr><td>' . fdate($x['expense_date']) . '</td><td>' . e($x['category']) . '</td><td>' . e($x['description']) . '</td><td>' . e($x['chargeable_to']) . '</td><td class="num">' . money($x['amount'], false) . '</td></tr>'; echo '</tbody></table>'; }
            break;
        case 'landlord':
            $p = one("SELECT * FROM prpt_parties WHERE id = ?", [$id]);
            if (!$p) return ['title' => 'Not found', 'html' => '<p>Not found.</p>'];
            $title = 'Landlord statement — ' . $p['display_name'];
            $props = col("SELECT DISTINCT u.property_id FROM prpt_units u WHERE u.id IN (" . (in_ints(array_keys(party_owned_units($id))) ?: '0') . ")");
            if (!empty($f['property_id'])) $props = array_values(array_filter($props, fn($x) => (int)$x === (int)$f['property_id']));
            echo '<div class="grid2"><div><h1>LANDLORD STATEMENT</h1><p><b>' . e($p['display_name']) . '</b><br><span class="muted">' . e($p['party_no']) . '</span></p></div><div class="right"><p>' . e($period) . '</p></div></div>';
            $G = ['gross' => 0, 'fee' => 0, 'fee_vat' => 0, 'expenses' => 0, 'net' => 0, 'remitted' => 0, 'unremitted' => 0];
            foreach ($props as $pid) {
                $pos = landlord_position($id, (int)$pid, $from, $to); foreach ($G as $k => $_) $G[$k] += $pos[$k];
                echo '<h2>' . e((string)val("SELECT name FROM prpt_properties WHERE id = ?", [$pid])) . '</h2><table><thead><tr><th>Unit</th><th>Bill</th><th class="num">Collected</th><th class="num">Share</th><th class="num">Yours</th></tr></thead><tbody>';
                foreach ($pos['lines'] as $l) echo '<tr><td>' . e($l['unit']) . '</td><td>' . e($l['bill']) . '</td><td class="num">' . money($l['collected'], false) . '</td><td class="num">' . num($l['share_pct'], 2) . '%</td><td class="num">' . money($l['owner_amount'], false) . '</td></tr>';
                if (!$pos['lines']) echo '<tr><td colspan="5" class="muted">No owner income collected in this period.</td></tr>';
                echo '<tr><td colspan="4">Management fee (' . e($pos['fee_label']) . ')' . ($pos['fee_vat'] > 0 ? ' + VAT' : '') . '</td><td class="num">−' . money($pos['fee'] + $pos['fee_vat'], false) . '</td></tr>';
                foreach ($pos['expense_rows'] as $x) echo '<tr><td colspan="4">Expense · ' . fdate($x['expense_date']) . ' · ' . e($x['description']) . '</td><td class="num">−' . money($x['owner_share'], false) . '</td></tr>';
                echo '<tr class="tot"><td colspan="4">Net earned</td><td class="num">' . money($pos['net'], false) . '</td></tr><tr><td colspan="4">Remitted</td><td class="num">' . money($pos['remitted'], false) . '</td></tr><tr><td colspan="4"><b>Unremitted</b></td><td class="num"><b>' . money($pos['unremitted'], false) . '</b></td></tr></tbody></table>';
            }
            if (!$props) echo '<p class="muted">No owned units on record.</p>';
            echo '<div class="kpis"><div class="box">Gross<b>' . money($G['gross']) . '</b></div><div class="box">Fees + VAT<b>' . money($G['fee'] + $G['fee_vat']) . '</b></div><div class="box">Remitted<b>' . money($G['remitted']) . '</b></div><div class="box">Unremitted<b>' . money($G['unremitted']) . '</b></div></div>';
            $rem = all("SELECT * FROM fin_remittances WHERE party_id = ? AND status <> 'cancelled' AND period_end BETWEEN ? AND ? ORDER BY period_end", [$id, $from, $to]);
            if ($rem) { echo '<h2>Remittances</h2><table><thead><tr><th>No.</th><th>Period</th><th>Status</th><th>Paid</th><th class="num">Net</th></tr></thead><tbody>'; foreach ($rem as $x) echo '<tr><td>' . e($x['remittance_no']) . '</td><td>' . fdate($x['period_start']) . ' – ' . fdate($x['period_end']) . '</td><td>' . e($x['status']) . '</td><td>' . ($x['paid_at'] ? fdate($x['paid_at']) . ' ' . e($x['payment_ref']) : '—') . '</td><td class="num">' . money($x['net_payable'], false) . '</td></tr>'; echo '</tbody></table>'; }
            break;
        default: return ['title' => 'Statement', 'html' => '<p>Unknown statement type.</p>'];
    }
    return ['title' => $title, 'html' => ob_get_clean()];
}
function statement_party_block(int $partyId, array $f, string $sub, string $btName, string $period): string {
    $p = one("SELECT * FROM prpt_parties WHERE id = ?", [$partyId]);
    $s = statement_party($partyId, $f);
    $h = '<div class="grid2"><div><h1>STATEMENT OF ACCOUNT</h1><p><b>' . e($p['display_name'] ?? '') . '</b><br><span class="muted">' . e(($p['party_no'] ?? '') . ' · ' . ($p['phone'] ?? '')) . '</span><br>' . e($sub) . '</p></div>'
       . '<div class="right"><p>' . e($period) . '<br>' . e($btName) . '</p><p class="big">' . money($s['closing']) . '</p><p class="muted">' . ($s['closing'] > 0 ? 'balance due' : ($s['closing'] < 0 ? 'in credit' : 'fully paid')) . '</p></div></div>'
       . '<table><thead><tr><th>Date</th><th>Reference</th><th>Details</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody><tr><td colspan="5"><i>Opening balance</i></td><td class="num">' . money($s['opening'], false) . '</td></tr>';
    foreach ($s['rows'] as $r) $h .= '<tr><td>' . fdate($r['d']) . '</td><td>' . e($r['ref']) . '</td><td class="muted">' . e(excerpt((string)$r['descr'], 90)) . '</td><td class="num">' . ((float)$r['debit'] ? money($r['debit'], false) : '') . '</td><td class="num">' . ((float)$r['credit'] ? money($r['credit'], false) : '') . '</td><td class="num">' . money($r['balance'], false) . '</td></tr>';
    $h .= '<tr class="tot"><td colspan="3">Totals / closing balance</td><td class="num">' . money($s['totals']['debit'], false) . '</td><td class="num">' . money($s['totals']['credit'], false) . '</td><td class="num">' . money($s['closing'], false) . '</td></tr></tbody></table>';
    $credit = (float)val("SELECT COALESCE(SUM(remaining),0) FROM fin_credits WHERE party_id = ? AND status = 'open'", [$partyId]);
    if ($credit > 0) $h .= '<p class="muted">Includes ' . money($credit) . ' held as prepayment / overpayment credit, applied automatically to your next bills.</p>';
    return $h . '<div class="box"><b>Pay via M-Pesa</b> Paybill ' . e(setting('paybill_number', '—')) . ', account = your house number. ' . e(setting('bank_details', '')) . '</div>';
}
