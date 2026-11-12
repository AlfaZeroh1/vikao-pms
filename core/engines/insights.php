<?php
/**
 * AI insights. Two engines:
 *  - rules: deterministic analysis over portfolio KPIs (always available, explainable)
 *  - llm:   the same KPIs sent to an LLM (Anthropic integration) for a narrative briefing
 */

function portfolio_kpis(?int $propertyId = null): array {
    $ps = $propertyId ? ' AND property_id = ' . (int)$propertyId : ''; $psU = $propertyId ? ' AND u.property_id = ' . (int)$propertyId : '';
    $m0 = date('Y-m-01'); $m1 = date('Y-m-01', strtotime('-1 month')); $m1e = date('Y-m-t', strtotime('-1 month'));
    $k = [];
    $k['units'] = (int)val("SELECT COUNT(*) FROM prpt_units u WHERE deleted_at IS NULL $psU");
    $k['occupied'] = (int)val("SELECT COUNT(*) FROM prpt_units u WHERE deleted_at IS NULL AND status IN ('occupied','owner_occupied') $psU");
    $k['vacant'] = (int)val("SELECT COUNT(*) FROM prpt_units u WHERE deleted_at IS NULL AND status = 'vacant' $psU");
    $k['occupancy_pct'] = $k['units'] ? round($k['occupied'] / $k['units'] * 100, 1) : 0;
    $k['invoiced_this_month'] = (float)val("SELECT COALESCE(SUM(total),0) FROM bln_invoices WHERE status NOT IN ('void','draft') AND issue_date >= ? $ps", [$m0]);
    $k['collected_this_month'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM fin_payments WHERE status <> 'reversed' AND paid_at >= ? $ps", [$m0]);
    $k['invoiced_last_month'] = (float)val("SELECT COALESCE(SUM(total),0) FROM bln_invoices WHERE status NOT IN ('void','draft') AND issue_date BETWEEN ? AND ? $ps", [$m1, $m1e]);
    $k['collected_last_month'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM fin_payments WHERE status <> 'reversed' AND paid_at BETWEEN ? AND ? $ps", [$m1, $m1e . ' 23:59:59']);
    $k['collection_rate_last_month'] = $k['invoiced_last_month'] > 0 ? round($k['collected_last_month'] / $k['invoiced_last_month'] * 100, 1) : null;
    $k['arrears_total'] = (float)val("SELECT COALESCE(SUM(balance),0) FROM bln_invoices WHERE status IN ('issued','partially_paid','overdue') AND due_date < CURDATE() $ps");
    foreach ([[1, 30], [31, 60], [61, 90], [91, 99999]] as [$a, $b]) $k["aging_{$a}_{$b}"] = (float)val("SELECT COALESCE(SUM(balance),0) FROM bln_invoices WHERE status IN ('issued','partially_paid','overdue') AND DATEDIFF(CURDATE(), due_date) BETWEEN ? AND ? $ps", [$a, $b]);
    $k['top_debtors'] = all("SELECT p.display_name, SUM(i.balance) amt, MIN(i.due_date) oldest FROM bln_invoices i JOIN prpt_parties p ON p.id = i.party_id WHERE i.status IN ('issued','partially_paid','overdue') AND i.due_date < CURDATE() " . str_replace('property_id', 'i.property_id', $ps) . " GROUP BY i.party_id ORDER BY amt DESC LIMIT 5");
    $k['unallocated_payments'] = (float)val("SELECT COALESCE(SUM(unallocated_amount),0) FROM fin_payments WHERE status IN ('unallocated','partially_allocated') $ps");
    $k['unallocated_count'] = (int)val("SELECT COUNT(*) FROM fin_payments WHERE status IN ('unallocated','partially_allocated') $ps");
    $k['open_tickets'] = (int)val("SELECT COUNT(*) FROM crm_tickets WHERE status NOT IN ('resolved','closed','cancelled') $ps");
    $k['sla_breached'] = (int)val("SELECT COUNT(*) FROM crm_tickets WHERE status NOT IN ('resolved','closed','cancelled') AND sla_due_at < NOW() $ps");
    $k['avg_resolution_hours'] = (float)val("SELECT COALESCE(AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)),0) FROM crm_tickets WHERE resolved_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) $ps");
    $k['leases_expiring_60d'] = (int)val("SELECT COUNT(*) FROM bln_leases WHERE status = 'active' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) $ps");
    $k['expenses_this_month'] = (float)val("SELECT COALESCE(SUM(amount),0) FROM fin_expenses WHERE status <> 'rejected' AND expense_date >= ? $ps", [$m0]);
    $k['reading_anomalies'] = (int)val("SELECT COUNT(*) FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id WHERE r.anomaly_flag IS NOT NULL AND r.status = 'pending'" . ($propertyId ? ' AND m.property_id = ' . (int)$propertyId : ''));
    $k['long_vacant'] = all("SELECT u.unit_no, pr.name AS property, DATEDIFF(CURDATE(), COALESCE((SELECT MAX(created_at) FROM prpt_unit_status_log s WHERE s.unit_id = u.id AND s.to_status = 'vacant'), u.created_at)) AS days
                             FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.status = 'vacant' AND u.purpose IN ('rent','rent_and_sale') AND u.deleted_at IS NULL $psU HAVING days > 45 ORDER BY days DESC LIMIT 5");
    $k['sst_occupancy_30d'] = (float)val("SELECT COALESCE(SUM(LEAST(b.check_out, CURDATE()) - GREATEST(b.check_in, DATE_SUB(CURDATE(), INTERVAL 30 DAY))) / NULLIF(COUNT(DISTINCT s.id) * 30, 0) * 100, 0)
                             FROM sst_listings s LEFT JOIN sst_bookings b ON b.listing_id = s.id AND b.status IN ('confirmed','checked_in','checked_out') AND b.check_out > DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND b.check_in < CURDATE()");
    $k['open_leads'] = (int)val("SELECT COUNT(*) FROM sls_leads l JOIN sls_stages s ON s.id = l.stage_id WHERE s.is_won = 0 AND s.is_lost = 0");
    $k['overdue_followups'] = (int)val("SELECT COUNT(*) FROM sls_leads WHERE next_follow_up_at < NOW()");
    return $k;
}

function insights_rules(array $k): array {
    $out = [];
    $add = function ($sev, $title, $detail, $action, $link = null) use (&$out) { $out[] = compact('sev', 'title', 'detail', 'action', 'link'); };
    if ($k['collection_rate_last_month'] !== null) {
        if ($k['collection_rate_last_month'] < 85) $add('critical', 'Collection rate dropped to ' . $k['collection_rate_last_month'] . '%', 'Last month ' . money($k['collected_last_month']) . ' was collected against ' . money($k['invoiced_last_month']) . ' invoiced.', 'Send arrears reminders and call the top 5 debtors this week.', page('billing/arrears'));
        else $add('good', 'Healthy collections: ' . $k['collection_rate_last_month'] . '%', 'Collections kept pace with billing last month.', 'Keep automatic reminders on.');
    }
    $old = $k['aging_61_90'] + $k['aging_91_99999'];
    if ($k['arrears_total'] > 0 && $old / $k['arrears_total'] > 0.35) $add('critical', round($old / $k['arrears_total'] * 100) . '% of arrears are older than 60 days', money($old) . ' is 60+ days overdue — the hardest money to recover.', 'Issue demand notices and agree payment plans; consider holding deposits.', page('reports/view', ['code' => 'arrears_aging']));
    if ($k['top_debtors']) { $t = $k['top_debtors'][0]; $share = $k['arrears_total'] > 0 ? round($t['amt'] / $k['arrears_total'] * 100) : 0; if ($share >= 20) $add('warning', $t['display_name'] . ' holds ' . $share . '% of all arrears', money($t['amt']) . ' outstanding since ' . fdate($t['oldest']) . '.', 'Escalate this account personally.'); }
    if ($k['unallocated_count'] > 0) $add('warning', plural($k['unallocated_count'], 'payment') . ' not yet consumed', money($k['unallocated_payments']) . ' received but not applied to bills — tenants will look like defaulters.', 'Clear them on the Consume page.', page('finance/payments', ['status' => 'unallocated']));
    if ($k['occupancy_pct'] < 85 && $k['units'] > 0) $add('warning', 'Occupancy at ' . $k['occupancy_pct'] . '%', $k['vacant'] . ' vacant units are earning nothing.', 'Publish vacant units to the Vikao marketplace from the Ecommerce module.', page('ecommerce/push'));
    foreach ($k['long_vacant'] as $v) $add('info', "Unit {$v['unit_no']} ({$v['property']}) vacant {$v['days']} days", 'Long vacancies usually signal pricing or condition issues.', 'Review the asking rent, refresh photos, or offer a move-in incentive.');
    if ($k['sla_breached'] > 0) $add('critical', plural($k['sla_breached'], 'ticket') . ' breached SLA', 'Customers are waiting longer than promised.', 'Reassign or escalate overdue tickets now.', page('crm/tickets', ['sla' => 'breached']));
    if ($k['avg_resolution_hours'] > 72) $add('warning', 'Average resolution time is ' . round($k['avg_resolution_hours']) . 'h', 'Slow fixes are the #1 driver of tenant churn.', 'Add vendors to the panel and set preventive maintenance schedules.');
    if ($k['leases_expiring_60d'] > 0) $add('info', plural($k['leases_expiring_60d'], 'lease') . ' expire within 60 days', 'Renewals are cheaper than re-letting.', 'Send renewal offers now.', page('billing/leases', ['expiring' => 60]));
    if ($k['reading_anomalies'] > 0) $add('warning', plural($k['reading_anomalies'], 'meter reading') . ' flagged as anomalies', 'Readings far above usual consumption are held for review (possible leaks or typos).', 'Approve or correct before the billing run.', page('property/readings', ['status' => 'pending']));
    if ($k['overdue_followups'] > 0) $add('info', plural($k['overdue_followups'], 'sales follow-up') . ' overdue', 'Leads go cold within days.', 'Work the follow-up queue in Sales.', page('sales/leads'));
    if ($k['invoiced_this_month'] > 0 && $k['expenses_this_month'] / max(1, $k['invoiced_this_month']) > 0.3) $add('warning', 'Expenses are ' . round($k['expenses_this_month'] / $k['invoiced_this_month'] * 100) . '% of billing this month', 'Unusually high cost ratio eats into owner remittances.', 'Review expense approvals and recoverable costs.');
    if (!$out) $add('good', 'All clear', 'No risks detected in the current data.', 'Keep going 🎉');
    $order = ['critical' => 0, 'warning' => 1, 'info' => 2, 'good' => 3];
    usort($out, fn($a, $b) => $order[$a['sev']] <=> $order[$b['sev']]);
    return $out;
}

/** Generate & store insights. Uses the LLM when the Anthropic integration is live, otherwise rules. */
function generate_insights(string $scope = 'portfolio', ?int $propertyId = null, bool $useLlm = true): array {
    $k = portfolio_kpis($propertyId);
    $rules = insights_rules($k);
    $engine = 'rules'; $model = null; $insights = $rules;
    if ($useLlm && ($ai = integration('anthropic')) && $ai['is_active'] && !$ai['simulate']) {
        try {
            $res = anthropic_insights($k, $rules);
            if ($res) { $insights = $res['insights']; $engine = 'llm'; $model = $res['model']; }
        } catch (Throwable $e) { error_log('[insights] ' . $e->getMessage()); }
    }
    $id = insert('rpt_ai_insights', ['scope' => $scope, 'period_label' => date('M Y'), 'insights' => json_encode($insights, JSON_UNESCAPED_UNICODE), 'engine' => $engine, 'model' => $model, 'generated_by' => staff_id()]);
    return ['id' => $id, 'engine' => $engine, 'model' => $model, 'insights' => $insights, 'kpis' => $k];
}
