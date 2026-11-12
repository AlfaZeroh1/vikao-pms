<?php
/**
 * Report catalogue. Each report: [title, icon, section, description, fn(array $f): array{cols, rows, chart?, note?}]
 * $f: property_id, from, to. Columns: key => [label, type(text|money|num|pct|date)].
 */
function report_catalogue(): array {
    $P = fn(array $f, string $col) => ($f['property_id'] ? " AND $col = " . (int)$f['property_id'] : '') . ' AND ' . scope_sql($col);
    return [
        'rent_roll' => ['Rent roll', 'fa-list-ol', 'Leasing', 'Every active account: tenant, rent, service charge, deposit and balance', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, u.unit_no, l.lease_no, l.occupancy_type, (SELECT GROUP_CONCAT(p.display_name SEPARATOR ', ') FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = l.id AND lp.role IN ('tenant','co_tenant','occupant')) AS tenant,
                l.start_date, l.end_date, (SELECT COALESCE(SUM(amount),0) FROM bln_lease_charges c JOIN cfg_bill_types bt ON bt.id = c.bill_type_id WHERE c.lease_id = l.id AND c.is_active = 1 AND bt.code = 'RENT') AS rent,
                (SELECT COALESCE(SUM(amount),0) FROM bln_lease_charges c JOIN cfg_bill_types bt ON bt.id = c.bill_type_id WHERE c.lease_id = l.id AND c.is_active = 1 AND bt.code = 'SC') AS sc,
                (SELECT COALESCE(SUM(amount_held),0) FROM bln_deposits d WHERE d.lease_id = l.id AND d.status IN ('held','partially_refunded')) AS deposit,
                (SELECT COALESCE(SUM(balance),0) FROM bln_invoices i WHERE i.lease_id = l.id AND i.status IN ('issued','partially_paid','overdue')) AS balance
                FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE l.status IN ('active','notice')" . $P($f, 'l.property_id') . " ORDER BY pr.name, u.unit_no");
            return ['cols' => ['property' => ['Property'], 'unit_no' => ['Unit'], 'lease_no' => ['Account'], 'occupancy_type' => ['Occupancy'], 'tenant' => ['Tenant'], 'start_date' => ['From', 'date'], 'end_date' => ['To', 'date'], 'rent' => ['Rent', 'money'], 'sc' => ['Service ch.', 'money'], 'deposit' => ['Deposit held', 'money'], 'balance' => ['Balance', 'money']], 'rows' => $rows, 'totals' => ['rent', 'sc', 'deposit', 'balance']];
        }],
        'occupancy' => ['Occupancy & vacancy', 'fa-house-circle-check', 'Leasing', 'Units by status per property, occupancy rate and rent lost to vacancy', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, COUNT(*) units, SUM(u.status = 'occupied') occupied, SUM(u.status = 'owner_occupied') owner_occupied, SUM(u.status = 'vacant') vacant, SUM(u.status IN ('reserved','under_maintenance','unavailable')) other,
                ROUND(SUM(u.status IN ('occupied','owner_occupied')) / COUNT(*) * 100, 1) pct, SUM(CASE WHEN u.status = 'vacant' THEN u.rent_amount ELSE 0 END) lost
                FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.deleted_at IS NULL" . $P($f, 'u.property_id') . " GROUP BY pr.id ORDER BY pct");
            return ['cols' => ['property' => ['Property'], 'units' => ['Units', 'num'], 'occupied' => ['Let', 'num'], 'owner_occupied' => ['Owner occ.', 'num'], 'vacant' => ['Vacant', 'num'], 'other' => ['Other', 'num'], 'pct' => ['Occupancy', 'pct'], 'lost' => ['Monthly rent lost', 'money']], 'rows' => $rows, 'totals' => ['units', 'occupied', 'owner_occupied', 'vacant', 'other', 'lost'],
                'chart' => ['type' => 'bar', 'labels' => array_column($rows, 'property'), 'series' => ['Occupancy %' => array_map('floatval', array_column($rows, 'pct'))]]];
        }],
        'vacant_units' => ['Vacant units', 'fa-door-open', 'Leasing', 'Every vacant unit, days vacant and asking rent', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, u.unit_no, u.unit_type, u.bedrooms, u.rent_amount, DATEDIFF(CURDATE(), COALESCE((SELECT MAX(created_at) FROM prpt_unit_status_log s WHERE s.unit_id = u.id AND s.to_status = 'vacant'), u.created_at)) AS days, IF(u.ecom_listing_uuid IS NULL, 'No', 'Yes') AS on_marketplace
                FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.status = 'vacant' AND u.deleted_at IS NULL" . $P($f, 'u.property_id') . " ORDER BY days DESC");
            return ['cols' => ['property' => ['Property'], 'unit_no' => ['Unit'], 'unit_type' => ['Type'], 'bedrooms' => ['Beds', 'num'], 'rent_amount' => ['Asking rent', 'money'], 'days' => ['Days vacant', 'num'], 'on_marketplace' => ['On vikao.io']], 'rows' => $rows, 'totals' => ['rent_amount']];
        }],
        'lease_expiry' => ['Lease expiry', 'fa-hourglass-half', 'Leasing', 'Accounts ending in the next 120 days', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, u.unit_no, l.lease_no, (SELECT GROUP_CONCAT(p.display_name) FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = l.id AND lp.role = 'tenant') AS tenant, l.end_date, DATEDIFF(l.end_date, CURDATE()) days, l.rent_amount, l.status
                FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE l.status IN ('active','notice') AND l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)" . $P($f, 'l.property_id') . " ORDER BY l.end_date");
            return ['cols' => ['property' => ['Property'], 'unit_no' => ['Unit'], 'lease_no' => ['Account'], 'tenant' => ['Tenant'], 'end_date' => ['Ends', 'date'], 'days' => ['Days left', 'num'], 'rent_amount' => ['Rent', 'money'], 'status' => ['Status']], 'rows' => $rows];
        }],
        'arrears_aging' => ['Arrears aging', 'fa-triangle-exclamation', 'Finance', 'Overdue balances per property by age bucket', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 1 AND 30 THEN i.balance ELSE 0 END) d30, SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 31 AND 60 THEN i.balance ELSE 0 END) d60,
                SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) BETWEEN 61 AND 90 THEN i.balance ELSE 0 END) d90, SUM(CASE WHEN DATEDIFF(CURDATE(), i.due_date) > 90 THEN i.balance ELSE 0 END) d90p, SUM(i.balance) total, COUNT(DISTINCT i.party_id) debtors
                FROM bln_invoices i JOIN prpt_properties pr ON pr.id = i.property_id WHERE i.status IN ('issued','partially_paid','overdue') AND i.due_date < CURDATE()" . $P($f, 'i.property_id') . " GROUP BY pr.id ORDER BY total DESC");
            return ['cols' => ['property' => ['Property'], 'debtors' => ['Debtors', 'num'], 'd30' => ['1–30 days', 'money'], 'd60' => ['31–60', 'money'], 'd90' => ['61–90', 'money'], 'd90p' => ['90+', 'money'], 'total' => ['Total', 'money']], 'rows' => $rows, 'totals' => ['debtors', 'd30', 'd60', 'd90', 'd90p', 'total'],
                'chart' => ['type' => 'bar', 'stacked' => true, 'labels' => array_column($rows, 'property'), 'series' => ['1–30' => array_map('floatval', array_column($rows, 'd30')), '31–60' => array_map('floatval', array_column($rows, 'd60')), '61–90' => array_map('floatval', array_column($rows, 'd90')), '90+' => array_map('floatval', array_column($rows, 'd90p'))]]];
        }],
        'collections' => ['Collections', 'fa-money-bill-trend-up', 'Finance', 'Money received per month by method and account', function ($f) use ($P) {
            $rows = all("SELECT DATE_FORMAT(pm.paid_at, '%Y-%m') ym, SUM(CASE WHEN pm.method = 'mpesa' THEN pm.amount ELSE 0 END) mpesa, SUM(CASE WHEN pm.method IN ('bank_transfer','rtgs','eft','cheque') THEN pm.amount ELSE 0 END) bank,
                SUM(CASE WHEN pm.method = 'card' THEN pm.amount ELSE 0 END) card, SUM(CASE WHEN pm.method = 'cash' THEN pm.amount ELSE 0 END) cash, SUM(CASE WHEN pm.method IN ('other','offset') THEN pm.amount ELSE 0 END) other, SUM(pm.amount) total, COUNT(*) n
                FROM fin_payments pm WHERE pm.status <> 'reversed' AND DATE(pm.paid_at) BETWEEN :f AND :t" . ($f['property_id'] ? ' AND pm.property_id = ' . (int)$f['property_id'] : '') . " GROUP BY ym ORDER BY ym", ['f' => $f['from'], 't' => $f['to']]);
            foreach ($rows as &$r) $r['month'] = month_label($r['ym']);
            return ['cols' => ['month' => ['Month'], 'n' => ['Payments', 'num'], 'mpesa' => ['M-Pesa', 'money'], 'bank' => ['Bank', 'money'], 'card' => ['Card', 'money'], 'cash' => ['Cash', 'money'], 'other' => ['Other', 'money'], 'total' => ['Total', 'money']], 'rows' => $rows, 'totals' => ['n', 'mpesa', 'bank', 'card', 'cash', 'other', 'total'],
                'chart' => ['type' => 'bar', 'stacked' => true, 'labels' => array_column($rows, 'month'), 'series' => ['M-Pesa' => array_map('floatval', array_column($rows, 'mpesa')), 'Bank' => array_map('floatval', array_column($rows, 'bank')), 'Card' => array_map('floatval', array_column($rows, 'card')), 'Cash' => array_map('floatval', array_column($rows, 'cash'))]]];
        }],
        'income_by_bill' => ['Income by bill type', 'fa-tags', 'Finance', 'Invoiced vs collected per bill type for the period', function ($f) use ($P) {
            $rows = all("SELECT bt.name AS bill, SUM(l.amount + l.tax_amount) invoiced, SUM(l.amount_paid) collected, SUM(l.amount + l.tax_amount - l.amount_paid) outstanding, ROUND(SUM(l.amount_paid) / NULLIF(SUM(l.amount + l.tax_amount),0) * 100, 1) rate
                FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id JOIN cfg_bill_types bt ON bt.id = l.bill_type_id WHERE i.status NOT IN ('void','draft') AND i.issue_date BETWEEN :f AND :t" . $P($f, 'i.property_id') . " GROUP BY bt.id ORDER BY invoiced DESC", ['f' => $f['from'], 't' => $f['to']]);
            return ['cols' => ['bill' => ['Bill type'], 'invoiced' => ['Invoiced', 'money'], 'collected' => ['Collected', 'money'], 'outstanding' => ['Outstanding', 'money'], 'rate' => ['Collection rate', 'pct']], 'rows' => $rows, 'totals' => ['invoiced', 'collected', 'outstanding'],
                'chart' => ['type' => 'bar', 'labels' => array_column($rows, 'bill'), 'series' => ['Invoiced' => array_map('floatval', array_column($rows, 'invoiced')), 'Collected' => array_map('floatval', array_column($rows, 'collected'))]]];
        }],
        'expenses' => ['Expenses by category', 'fa-receipt', 'Finance', 'Spend per category and who bears it', function ($f) use ($P) {
            $rows = all("SELECT c.name AS category, SUM(CASE WHEN e.chargeable_to = 'owner' THEN e.amount ELSE 0 END) owner, SUM(CASE WHEN e.chargeable_to = 'agency' THEN e.amount ELSE 0 END) agency, SUM(CASE WHEN e.chargeable_to = 'property_fund' THEN e.amount ELSE 0 END) fund,
                SUM(CASE WHEN e.chargeable_to = 'tenant' THEN e.amount ELSE 0 END) tenant, SUM(e.amount) total, COUNT(*) n FROM fin_expenses e JOIN fin_expense_categories c ON c.id = e.category_id WHERE e.status <> 'rejected' AND e.expense_date BETWEEN :f AND :t" . ($f['property_id'] ? ' AND e.property_id = ' . (int)$f['property_id'] : '') . " GROUP BY c.id ORDER BY total DESC", ['f' => $f['from'], 't' => $f['to']]);
            return ['cols' => ['category' => ['Category'], 'n' => ['Items', 'num'], 'owner' => ['Owners', 'money'], 'agency' => ['Agency', 'money'], 'fund' => ['Property fund', 'money'], 'tenant' => ['Tenants', 'money'], 'total' => ['Total', 'money']], 'rows' => $rows, 'totals' => ['n', 'owner', 'agency', 'fund', 'tenant', 'total'],
                'chart' => ['type' => 'doughnut', 'labels' => array_column($rows, 'category'), 'series' => ['Total' => array_map('floatval', array_column($rows, 'total'))]]];
        }],
        'management_fees' => ['Management fees earned', 'fa-percent', 'Finance', 'Fees and VAT from owner remittances per property', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, COUNT(*) n, SUM(r.gross_collected) gross, SUM(r.mgmt_fee) fee, SUM(r.mgmt_fee_vat) vat, SUM(r.expenses) expenses, SUM(r.net_payable) net, SUM(CASE WHEN r.status = 'paid' THEN r.net_payable ELSE 0 END) paid
                FROM fin_remittances r JOIN prpt_properties pr ON pr.id = r.property_id WHERE r.status <> 'cancelled' AND r.period_end BETWEEN :f AND :t" . $P($f, 'r.property_id') . " GROUP BY pr.id ORDER BY fee DESC", ['f' => $f['from'], 't' => $f['to']]);
            return ['cols' => ['property' => ['Property'], 'n' => ['Remittances', 'num'], 'gross' => ['Owner income', 'money'], 'fee' => ['Mgmt fee', 'money'], 'vat' => ['VAT', 'money'], 'expenses' => ['Expenses', 'money'], 'net' => ['Net to owners', 'money'], 'paid' => ['Paid out', 'money']], 'rows' => $rows, 'totals' => ['n', 'gross', 'fee', 'vat', 'expenses', 'net', 'paid']];
        }],
        'owner_statement_summary' => ['Owners — remitted vs unremitted', 'fa-hand-holding-dollar', 'Finance', 'Per owner: earned, remitted and still owed for the period', function ($f) {
            $out = [];
            foreach (all("SELECT DISTINCT o.party_id, p.display_name FROM prpt_ownerships o JOIN prpt_parties p ON p.id = o.party_id WHERE o.end_date IS NULL OR o.end_date >= :f", ['f' => $f['from']]) as $o) {
                $props = col("SELECT DISTINCT u.property_id FROM prpt_units u WHERE u.id IN (" . (in_ints(array_keys(party_owned_units((int)$o['party_id']))) ?: '0') . ")");
                foreach ($props as $pid) { if ($f['property_id'] && (int)$pid !== (int)$f['property_id']) continue; $pos = landlord_position((int)$o['party_id'], (int)$pid, $f['from'], $f['to']);
                    $out[] = ['owner' => $o['display_name'], 'property' => val("SELECT name FROM prpt_properties WHERE id = ?", [$pid]), 'units' => count($pos['units']), 'gross' => $pos['gross'], 'fee' => $pos['fee'] + $pos['fee_vat'], 'expenses' => $pos['expenses'], 'net' => $pos['net'], 'remitted' => $pos['remitted'], 'unremitted' => $pos['unremitted']]; }
            }
            return ['cols' => ['owner' => ['Owner'], 'property' => ['Property'], 'units' => ['Units', 'num'], 'gross' => ['Collected (share)', 'money'], 'fee' => ['Fees + VAT', 'money'], 'expenses' => ['Expenses', 'money'], 'net' => ['Net earned', 'money'], 'remitted' => ['Remitted', 'money'], 'unremitted' => ['Unremitted', 'money']], 'rows' => $out, 'totals' => ['gross', 'fee', 'expenses', 'net', 'remitted', 'unremitted']];
        }],
        'meter_consumption' => ['Meter consumption', 'fa-gauge', 'Operations', 'Consumption per unit and utility for the period', function ($f) use ($P) {
            $rows = all("SELECT pr.name AS property, u.unit_no, bt.name AS utility, bt.unit_label, COUNT(r.id) readings, SUM(r.consumption) consumption, ROUND(AVG(r.consumption), 2) avg, SUM(r.anomaly_flag IS NOT NULL) anomalies
                FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id JOIN prpt_properties pr ON pr.id = m.property_id LEFT JOIN prpt_units u ON u.id = m.unit_id
                WHERE r.status <> 'rejected' AND r.reading_date BETWEEN :f AND :t" . $P($f, 'm.property_id') . " GROUP BY m.id ORDER BY consumption DESC", ['f' => $f['from'], 't' => $f['to']]);
            return ['cols' => ['property' => ['Property'], 'unit_no' => ['Unit'], 'utility' => ['Utility'], 'unit_label' => ['Unit'], 'readings' => ['Readings', 'num'], 'consumption' => ['Total used', 'num'], 'avg' => ['Avg / reading', 'num'], 'anomalies' => ['Anomalies', 'num']], 'rows' => $rows, 'totals' => ['readings', 'consumption', 'anomalies']];
        }],
        'tickets_sla' => ['Tickets & SLA', 'fa-headset', 'Operations', 'Volume, resolution time, SLA compliance and satisfaction per category', function ($f) use ($P) {
            $rows = all("SELECT c.name AS category, COUNT(*) n, SUM(t.status NOT IN ('resolved','closed','cancelled')) open, ROUND(AVG(CASE WHEN t.resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, t.created_at, t.resolved_at) END), 1) hours,
                ROUND(SUM(t.resolved_at IS NOT NULL AND t.resolved_at <= t.sla_due_at) / NULLIF(SUM(t.resolved_at IS NOT NULL), 0) * 100, 1) within_sla, ROUND(AVG(t.rating), 1) rating, SUM(COALESCE(t.cost_actual, 0)) cost
                FROM crm_tickets t JOIN crm_categories c ON c.id = t.category_id WHERE DATE(t.created_at) BETWEEN :f AND :t" . $P($f, 't.property_id') . " GROUP BY c.id ORDER BY n DESC", ['f' => $f['from'], 't' => $f['to']]);
            return ['cols' => ['category' => ['Category'], 'n' => ['Tickets', 'num'], 'open' => ['Open', 'num'], 'hours' => ['Avg hours to resolve', 'num'], 'within_sla' => ['Within SLA', 'pct'], 'rating' => ['Rating /5', 'num'], 'cost' => ['Cost', 'money']], 'rows' => $rows, 'totals' => ['n', 'open', 'cost']];
        }],
        'sales_funnel' => ['Sales funnel', 'fa-filter', 'Sales', 'Leads per stage and source; deals and commission', function ($f) {
            $rows = all("SELECT s.name AS stage, s.probability, COUNT(l.id) leads, SUM(l.source = 'vikao_ecommerce') marketplace, SUM(l.source = 'referral') referral, SUM(l.source = 'walk_in') walk_in,
                (SELECT COALESCE(SUM(d.agreed_price),0) FROM sls_deals d JOIN sls_leads x ON x.id = d.lead_id WHERE x.stage_id = s.id AND d.status <> 'cancelled') AS deal_value
                FROM sls_stages s LEFT JOIN sls_leads l ON l.stage_id = s.id AND DATE(l.created_at) BETWEEN :f AND :t GROUP BY s.id ORDER BY s.sort_order", ['f' => $f['from'], 't' => $f['to']]);
            return ['cols' => ['stage' => ['Stage'], 'probability' => ['Win %', 'pct'], 'leads' => ['Leads', 'num'], 'marketplace' => ['From vikao.io', 'num'], 'referral' => ['Referral', 'num'], 'walk_in' => ['Walk-in', 'num'], 'deal_value' => ['Deal value', 'money']], 'rows' => $rows, 'totals' => ['leads', 'marketplace', 'referral', 'walk_in', 'deal_value'],
                'chart' => ['type' => 'bar', 'horizontal' => true, 'labels' => array_column($rows, 'stage'), 'series' => ['Leads' => array_map('intval', array_column($rows, 'leads'))]]];
        }],
        'short_stay' => ['Short-stay performance', 'fa-bed', 'Short stay', 'Occupancy, ADR, RevPAR, revenue and channel mix per listing', function ($f) {
            $days = max(1, (int)((strtotime($f['to']) - strtotime($f['from'])) / 86400) + 1); $out = [];
            foreach (all("SELECT s.id, s.title, u.unit_no FROM sst_listings s JOIN prpt_units u ON u.id = s.unit_id WHERE s.status <> 'archived'") as $s) {
                $b = one("SELECT COUNT(*) n, COALESCE(SUM(DATEDIFF(LEAST(check_out, DATE_ADD(:t, INTERVAL 1 DAY)), GREATEST(check_in, :f))),0) nights, COALESCE(SUM(accommodation_total / nights * DATEDIFF(LEAST(check_out, DATE_ADD(:t, INTERVAL 1 DAY)), GREATEST(check_in, :f))),0) rev,
                    COALESCE(SUM(channel_commission),0) comm, SUM(channel <> 'direct') ota FROM sst_bookings WHERE listing_id = :l AND status IN ('confirmed','checked_in','checked_out') AND check_in <= :t AND check_out > :f", ['l' => $s['id'], 'f' => $f['from'], 't' => $f['to']]);
                $out[] = ['listing' => $s['title'], 'unit_no' => $s['unit_no'], 'bookings' => (int)$b['n'], 'nights' => (int)$b['nights'], 'occ' => round($b['nights'] / $days * 100, 1), 'adr' => $b['nights'] ? round($b['rev'] / $b['nights'], 2) : 0, 'revpar' => round($b['rev'] / $days, 2), 'revenue' => round2($b['rev']), 'commission' => (float)$b['comm'], 'ota' => (int)$b['ota']];
            }
            return ['cols' => ['listing' => ['Listing'], 'unit_no' => ['Unit'], 'bookings' => ['Bookings', 'num'], 'nights' => ['Nights sold', 'num'], 'occ' => ['Occupancy', 'pct'], 'adr' => ['ADR', 'money'], 'revpar' => ['RevPAR', 'money'], 'revenue' => ['Room revenue', 'money'], 'commission' => ['OTA commission', 'money'], 'ota' => ['OTA bookings', 'num']], 'rows' => $out, 'totals' => ['bookings', 'nights', 'revenue', 'commission', 'ota'],
                'chart' => ['type' => 'bar', 'labels' => array_column($out, 'listing'), 'series' => ['Occupancy %' => array_column($out, 'occ')]]];
        }],
        'portal_adoption' => ['Self-service adoption', 'fa-mobile-screen', 'Operations', 'Portal accounts invited, activated, installed as app and paying online', function ($f) {
            $rows = all("SELECT pr.name AS property, COUNT(DISTINCT p.id) people, COUNT(DISTINCT CASE WHEN a.activation_sent_at IS NOT NULL THEN p.id END) invited, COUNT(DISTINCT CASE WHEN a.status = 'active' THEN p.id END) active, COUNT(DISTINCT CASE WHEN a.app_installed_at IS NOT NULL THEN p.id END) installed,
                COUNT(DISTINCT CASE WHEN a.last_login_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN p.id END) active30
                FROM bln_lease_parties lp JOIN bln_leases l ON l.id = lp.lease_id AND l.status IN ('active','notice') JOIN prpt_properties pr ON pr.id = l.property_id JOIN prpt_parties p ON p.id = lp.party_id LEFT JOIN prt_accounts a ON a.party_id = p.id
                WHERE 1=1" . ($f['property_id'] ? ' AND l.property_id = ' . (int)$f['property_id'] : '') . " GROUP BY pr.id");
            foreach ($rows as &$r) $r['rate'] = $r['people'] ? round($r['active'] / $r['people'] * 100, 1) : 0;
            return ['cols' => ['property' => ['Property'], 'people' => ['Tenants & owners', 'num'], 'invited' => ['Invited', 'num'], 'active' => ['Activated', 'num'], 'rate' => ['Adoption', 'pct'], 'installed' => ['Installed app', 'num'], 'active30' => ['Active 30 d', 'num']], 'rows' => $rows, 'totals' => ['people', 'invited', 'active', 'installed', 'active30']];
        }],
    ];
}
