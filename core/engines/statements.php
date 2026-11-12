<?php
/**
 * Statements (tenant / owner-as-payer / unit / property / landlord) and owner remittances.
 * Bill-type-specific statements use allocation rows so credits match the bills exactly.
 */

/**
 * Account statement for a payer (tenant or owner who is billed).
 * $f: from, to, unit_id?, lease_id?, bill_type_id?
 * @return array{opening: float, rows: array, closing: float, totals: array}
 */
function statement_party(int $partyId, array $f): array {
    $from = $f['from'] ?? date('Y-01-01'); $to = $f['to'] ?? date('Y-m-d');
    $bt = !empty($f['bill_type_id']) ? (int)$f['bill_type_id'] : null;
    $unitSql = !empty($f['unit_id']) ? ' AND i.unit_id = ' . (int)$f['unit_id'] : '';
    $leaseSql = !empty($f['lease_id']) ? ' AND i.lease_id = ' . (int)$f['lease_id'] : '';
    $btSql = $bt ? " AND l.bill_type_id = $bt" : '';
    $debits = "SELECT i.issue_date AS d, CONCAT('Invoice ', i.invoice_no) AS ref, i.id AS invoice_id, i.uuid AS invoice_uuid, GROUP_CONCAT(l.description SEPARATOR '; ') AS descr,
               SUM(l.amount + l.tax_amount) AS debit, 0 AS credit, i.created_at AS ts
               FROM bln_invoices i JOIN bln_invoice_lines l ON l.invoice_id = i.id
               WHERE i.party_id = :p AND i.status NOT IN ('void','draft') $unitSql $leaseSql $btSql";
    $notes = "SELECT DATE(cn.created_at) AS d, CONCAT('Credit note ', cn.credit_no) AS ref, cn.invoice_id, i.uuid AS invoice_uuid, cn.reason AS descr, 0 AS debit, cn.amount AS credit, cn.created_at AS ts
              FROM bln_credit_notes cn JOIN bln_invoices i ON i.id = cn.invoice_id WHERE i.party_id = :p $unitSql $leaseSql";
    // credits: allocations (bill-specific) or whole payments (all bills, incl. unallocated / overpayments)
    if ($bt || $unitSql || $leaseSql) {
        $credits = "SELECT DATE(COALESCE(pm.paid_at, a.created_at)) AS d, CONCAT(IF(a.credit_id IS NULL, 'Payment ', 'Credit '), COALESCE(pm.receipt_no, CONCAT('#', a.credit_id))) AS ref, a.invoice_id, i.uuid AS invoice_uuid,
                    CONCAT('Paid on ', i.invoice_no, IF(pm.reference IS NULL, '', CONCAT(' · ', pm.reference))) AS descr, 0 AS debit, a.amount AS credit, COALESCE(pm.paid_at, a.created_at) AS ts
                    FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN bln_invoice_lines l ON l.id = a.invoice_line_id LEFT JOIN fin_payments pm ON pm.id = a.payment_id
                    WHERE a.reversed_at IS NULL AND a.credit_id IS NULL AND i.party_id = :p $unitSql $leaseSql $btSql";
    } else {
        $credits = "SELECT DATE(pm.paid_at) AS d, CONCAT('Receipt ', pm.receipt_no) AS ref, NULL AS invoice_id, NULL AS invoice_uuid,
                    CONCAT(UPPER(pm.method), IF(pm.reference IS NULL, '', CONCAT(' · ', pm.reference))) AS descr, 0 AS debit, pm.amount AS credit, pm.paid_at AS ts
                    FROM fin_payments pm WHERE pm.party_id = :p AND pm.status <> 'reversed'";
    }
    $union = "($debits GROUP BY i.id) UNION ALL ($notes) UNION ALL ($credits)";
    $opening = (float)val("SELECT COALESCE(SUM(debit - credit), 0) FROM ($union) x WHERE d < :from", ['p' => $partyId, 'from' => $from]);
    $rows = all("SELECT * FROM ($union) x WHERE d BETWEEN :from AND :to ORDER BY d, ts", ['p' => $partyId, 'from' => $from, 'to' => $to]);
    $bal = $opening; $td = 0; $tc = 0;
    foreach ($rows as &$r) { $bal = round2($bal + (float)$r['debit'] - (float)$r['credit']); $r['balance'] = $bal; $td += (float)$r['debit']; $tc += (float)$r['credit']; }
    return ['opening' => round2($opening), 'rows' => $rows, 'closing' => round2($bal), 'totals' => ['debit' => round2($td), 'credit' => round2($tc)], 'from' => $from, 'to' => $to];
}

/** Unit statement: every bill raised on the unit (any payer) and what was collected. */
function statement_unit(int $unitId, array $f): array {
    $from = $f['from'] ?? date('Y-01-01'); $to = $f['to'] ?? date('Y-m-d');
    $btSql = !empty($f['bill_type_id']) ? ' AND l.bill_type_id = ' . (int)$f['bill_type_id'] : '';
    $union = "(SELECT i.issue_date d, CONCAT('Invoice ', i.invoice_no) ref, p.display_name who, GROUP_CONCAT(l.description SEPARATOR '; ') descr, SUM(l.amount + l.tax_amount) debit, 0 credit, i.created_at ts
               FROM bln_invoices i JOIN bln_invoice_lines l ON l.invoice_id = i.id JOIN prpt_parties p ON p.id = i.party_id WHERE i.unit_id = :u AND i.status NOT IN ('void','draft') $btSql GROUP BY i.id)
              UNION ALL
              (SELECT DATE(COALESCE(pm.paid_at, a.created_at)) d, CONCAT('Paid ', i.invoice_no) ref, p.display_name who, COALESCE(CONCAT(pm.receipt_no, ' ', COALESCE(pm.reference,'')), 'Credit applied') descr, 0 debit, a.amount credit, COALESCE(pm.paid_at, a.created_at) ts
               FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN bln_invoice_lines l ON l.id = a.invoice_line_id JOIN prpt_parties p ON p.id = i.party_id LEFT JOIN fin_payments pm ON pm.id = a.payment_id
               WHERE a.reversed_at IS NULL AND i.unit_id = :u $btSql)";
    $opening = (float)val("SELECT COALESCE(SUM(debit - credit),0) FROM ($union) x WHERE d < :from", ['u' => $unitId, 'from' => $from]);
    $rows = all("SELECT * FROM ($union) x WHERE d BETWEEN :from AND :to ORDER BY d, ts", ['u' => $unitId, 'from' => $from, 'to' => $to]);
    $bal = $opening; foreach ($rows as &$r) { $bal = round2($bal + $r['debit'] - $r['credit']); $r['balance'] = $bal; }
    return ['opening' => round2($opening), 'rows' => $rows, 'closing' => round2($bal), 'from' => $from, 'to' => $to];
}

/** Property statement: per unit invoiced / collected / arrears + expenses for the period. */
function statement_property(int $propertyId, array $f): array {
    $from = $f['from'] ?? date('Y-m-01'); $to = $f['to'] ?? date('Y-m-d');
    $btSql = !empty($f['bill_type_id']) ? ' AND l.bill_type_id = ' . (int)$f['bill_type_id'] : '';
    $units = all("SELECT u.id, u.unit_no, u.status, b.code AS block_code,
        (SELECT COALESCE(SUM(l.amount + l.tax_amount),0) FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id WHERE i.unit_id = u.id AND i.status NOT IN ('void','draft') AND i.issue_date BETWEEN :f AND :t $btSql) AS invoiced,
        (SELECT COALESCE(SUM(a.amount),0) FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN bln_invoice_lines l ON l.id = a.invoice_line_id LEFT JOIN fin_payments pm ON pm.id = a.payment_id
            WHERE a.reversed_at IS NULL AND i.unit_id = u.id AND DATE(COALESCE(pm.paid_at, a.created_at)) BETWEEN :f AND :t $btSql) AS collected,
        (SELECT COALESCE(SUM(l.amount + l.tax_amount - l.amount_paid),0) FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id WHERE i.unit_id = u.id AND i.status NOT IN ('void','draft','written_off') AND i.issue_date <= :t $btSql) AS arrears,
        (SELECT p.display_name FROM bln_leases le JOIN bln_lease_parties lp ON lp.lease_id = le.id AND lp.role IN ('tenant','co_tenant') JOIN prpt_parties p ON p.id = lp.party_id WHERE le.unit_id = u.id AND le.status IN ('active','notice') ORDER BY lp.is_primary DESC LIMIT 1) AS tenant
        FROM prpt_units u LEFT JOIN prpt_blocks b ON b.id = u.block_id WHERE u.property_id = :pid AND u.deleted_at IS NULL ORDER BY b.code, u.unit_no",
        ['f' => $from, 't' => $to, 'pid' => $propertyId]);
    $expenses = all("SELECT e.*, c.name AS category FROM fin_expenses e JOIN fin_expense_categories c ON c.id = e.category_id WHERE e.property_id = ? AND e.expense_date BETWEEN ? AND ? AND e.status <> 'rejected' ORDER BY e.expense_date", [$propertyId, $from, $to]);
    $byBill = all("SELECT bt.name, bt.color_hex, SUM(l.amount + l.tax_amount) invoiced, SUM(l.amount_paid) paid FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id JOIN cfg_bill_types bt ON bt.id = l.bill_type_id
                   WHERE i.property_id = ? AND i.status NOT IN ('void','draft') AND i.issue_date BETWEEN ? AND ? GROUP BY bt.id ORDER BY invoiced DESC", [$propertyId, $from, $to]);
    $t = ['invoiced' => 0, 'collected' => 0, 'arrears' => 0];
    foreach ($units as $u) foreach ($t as $k => $_) $t[$k] += (float)$u[$k];
    $t['expenses'] = array_sum(array_map(fn($e) => (float)$e['amount'], $expenses));
    $occupied = count(array_filter($units, fn($u) => in_array($u['status'], ['occupied', 'owner_occupied'], true)));
    return ['units' => $units, 'expenses' => $expenses, 'by_bill' => $byBill, 'totals' => array_map('round2', $t), 'occupancy' => $units ? round($occupied / count($units) * 100, 1) : 0, 'from' => $from, 'to' => $to];
}

/* ------------------------------------------------------------------ landlord / owner */
function mgmt_agreement_for(int $partyId, int $propertyId): ?array {
    return one("SELECT * FROM prpt_mgmt_agreements WHERE property_id = ? AND (party_id = ? OR party_id IS NULL) AND is_active = 1 AND start_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE())
                ORDER BY party_id IS NULL, id DESC LIMIT 1", [$propertyId, $partyId]);
}

/**
 * Owner earnings for a property & period: money collected on the owner's units for bill types
 * remitted to owners (× ownership share), less management fee (+VAT), owner-chargeable expenses,
 * optional withholding (MRI), and what has already been remitted.
 */
function landlord_position(int $partyId, int $propertyId, string $from, string $to): array {
    $owned = array_filter(party_owned_units($partyId), fn($share, $uid) => (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$uid]) === $propertyId, ARRAY_FILTER_USE_BOTH);
    $ag = mgmt_agreement_for($partyId, $propertyId);
    $feeCodes = $ag && $ag['fee_bill_types'] ? array_map('trim', explode(',', $ag['fee_bill_types'])) : ['RENT'];
    $lines = []; $gross = 0.0; $feeBase = 0.0;
    foreach ($owned as $uid => $share) {
        $rows = all("SELECT bt.code, bt.name, SUM(a.amount) amt FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN cfg_bill_types bt ON bt.id = a.bill_type_id
                     LEFT JOIN fin_payments pm ON pm.id = a.payment_id
                     WHERE a.reversed_at IS NULL AND i.unit_id = ? AND bt.remit_to = 'owner' AND DATE(COALESCE(pm.paid_at, a.created_at)) BETWEEN ? AND ? GROUP BY bt.id", [$uid, $from, $to]);
        $unit = one("SELECT u.unit_no, u.status, b.code AS block_code FROM prpt_units u LEFT JOIN prpt_blocks b ON b.id = u.block_id WHERE u.id = ?", [$uid]);
        foreach ($rows as $r) {
            $amt = round2((float)$r['amt'] * $share / 100);
            $lines[] = ['unit' => unit_label($unit), 'bill' => $r['name'], 'collected' => (float)$r['amt'], 'share_pct' => $share, 'owner_amount' => $amt];
            $gross += $amt; if (in_array($r['code'], $feeCodes, true)) $feeBase += $amt;
        }
    }
    $months = max(1, (int)round((strtotime($to) - strtotime($from)) / (30.44 * 86400)));
    $fee = 0.0; $feeLabel = 'No agreement';
    if ($ag) {
        $occ = count(array_filter(array_keys($owned), fn($u) => in_array(val("SELECT status FROM prpt_units WHERE id = ?", [$u]), ['occupied'], true)));
        [$fee, $feeLabel] = match ($ag['fee_type']) {
            'percentage' => [$feeBase * (float)$ag['fee_value'] / 100, num($ag['fee_value'], 1) . '% of ' . implode('/', $feeCodes) . ' collected'],
            'fixed_per_occupied_unit' => [(float)$ag['fee_value'] * $occ * $months, money($ag['fee_value']) . " × $occ occupied unit(s) × $months mo"],
            'fixed_per_unit' => [(float)$ag['fee_value'] * count($owned) * $months, money($ag['fee_value']) . ' × ' . count($owned) . " unit(s) × $months mo"],
            'flat_monthly' => [(float)$ag['fee_value'] * $months, money($ag['fee_value']) . " × $months mo"],
        };
    }
    $fee = round2($fee); $vat = $ag && $ag['vat_on_fee'] ? round2($fee * (float)setting('vat_rate', 16) / 100) : 0.0;
    $expRows = all("SELECT e.*, c.name AS category FROM fin_expenses e JOIN fin_expense_categories c ON c.id = e.category_id
                    WHERE e.chargeable_to = 'owner' AND e.status IN ('approved','paid') AND e.expense_date BETWEEN ? AND ? AND e.property_id = ?
                    AND (e.party_id = ? OR (e.party_id IS NULL AND (e.unit_id IS NULL OR e.unit_id IN (" . in_ints(array_keys($owned)) . "))))", [$from, $to, $propertyId, $partyId]);
    // property-wide owner expenses are shared pro-rata by number of owned units
    $totalUnits = max(1, (int)val("SELECT COUNT(*) FROM prpt_units WHERE property_id = ? AND deleted_at IS NULL", [$propertyId]));
    $exp = 0.0;
    foreach ($expRows as &$e) { $e['owner_share'] = $e['unit_id'] || $e['party_id'] ? (float)$e['amount'] : round2((float)$e['amount'] * count($owned) / $totalUnits); $exp += $e['owner_share']; }
    $wht = round2($gross * (float)setting('remittance_withholding_pct', 0) / 100);
    $net = round2($gross - $fee - $vat - $exp - $wht);
    $remitted = (float)val("SELECT COALESCE(SUM(net_payable),0) FROM fin_remittances WHERE party_id = ? AND property_id = ? AND status = 'paid' AND period_start >= ? AND period_end <= ?", [$partyId, $propertyId, $from, $to]);
    return ['lines' => $lines, 'gross' => round2($gross), 'fee' => $fee, 'fee_label' => $feeLabel, 'fee_vat' => $vat, 'expenses' => round2($exp), 'expense_rows' => $expRows,
            'withholding' => $wht, 'net' => $net, 'remitted' => round2($remitted), 'unremitted' => round2($net - $remitted), 'agreement' => $ag, 'units' => $owned, 'from' => $from, 'to' => $to];
}

function remittance_create(int $partyId, int $propertyId, string $from, string $to): array {
    if (val("SELECT 1 FROM fin_remittances WHERE party_id = ? AND property_id = ? AND period_start = ? AND period_end = ? AND status <> 'cancelled'", [$partyId, $propertyId, $from, $to]))
        throw new RuntimeException('A remittance for this owner, property and period already exists.');
    $pos = landlord_position($partyId, $propertyId, $from, $to);
    $id = insert('fin_remittances', ['remittance_no' => next_no('remittance'), 'party_id' => $partyId, 'property_id' => $propertyId, 'period_start' => $from, 'period_end' => $to,
        'gross_collected' => $pos['gross'], 'mgmt_fee' => $pos['fee'], 'mgmt_fee_vat' => $pos['fee_vat'], 'expenses' => $pos['expenses'], 'withholding_tax' => $pos['withholding'],
        'net_payable' => $pos['net'], 'status' => 'draft', 'breakdown' => json_encode(['lines' => $pos['lines'], 'fee_label' => $pos['fee_label'],
        'expenses' => array_map(fn($e) => ['date' => $e['expense_date'], 'description' => $e['description'], 'amount' => $e['owner_share']], $pos['expense_rows'])]), 'created_by' => staff_id()]);
    foreach ($pos['expense_rows'] as $e) q("UPDATE fin_expenses SET remittance_id = ? WHERE id = ? AND remittance_id IS NULL", [$id, $e['id']]);
    audit('finance', 'create', 'remittance', $id, 'Remittance for owner #' . $partyId . ' ' . money($pos['net']));
    return one("SELECT * FROM fin_remittances WHERE id = ?", [$id]);
}
