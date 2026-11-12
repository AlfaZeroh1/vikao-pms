<?php
/**
 * Scheduler entry point. Run every 15 minutes:
 *   php /path/to/vikao-pms/cron.php                (all jobs)
 *   php cron.php billing,reminders                 (selected jobs)
 *   curl "https://pms.example.com/cron.php?token=YOUR_TOKEN&job=coop"
 * Jobs are idempotent — running them twice does no harm.
 */
define('VK_APP', 'cli');
require __DIR__ . '/core/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals((string)config('cron_token'), (string)($_GET['token'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
    $want = array_filter(explode(',', (string)($_GET['job'] ?? '')));
} else $want = array_filter(explode(',', (string)($argv[1] ?? '')));
@set_time_limit(600);
$log = function (string $job, $result) { $line = sprintf("[%s] %-12s %s\n", date('Y-m-d H:i:s'), $job, is_scalar($result) ? $result : json_encode($result)); echo $line; };
$jobs = [
    'overdue' => fn() => refresh_overdue() . ' invoice(s) marked overdue',
    'billing' => function () {
        $day = (int)date('j'); $runDay = (int)setting('billing_auto_run_day', 1);
        if ($day < $runDay) return "waiting for day $runDay";
        $period = date('Y-m');
        $due = (int)val("SELECT COUNT(*) FROM bln_leases WHERE status IN ('active','notice') AND auto_invoice = 1 AND billing_day <= ? AND (last_invoiced_period IS NULL OR last_invoiced_period < ?)", [$day, $period]);
        $pendingMeter = (int)val("SELECT COUNT(*) FROM prpt_meter_readings WHERE status = 'approved' AND invoice_line_id IS NULL");
        if (!$due && !$pendingMeter) return 'nothing due';
        $r = run_billing($period, null, [], false, 'scheduled');
        if ($r['invoices'] && setting('billing_auto_send', true)) foreach ($r['invoice_ids'] as $id) { try { invoice_send((int)$id); } catch (Throwable $e) { error_log((string)$e); } }
        return "{$r['invoices']} invoice(s), " . money($r['total']);
    },
    'escalation' => function () {
        $n = 0;
        foreach (all("SELECT * FROM bln_leases WHERE status = 'active' AND escalation_pct IS NOT NULL AND next_escalation_date IS NOT NULL AND next_escalation_date <= CURDATE()") as $l) {
            $rent = bill_type_by_code('RENT');
            foreach (all("SELECT * FROM bln_lease_charges WHERE lease_id = ? AND bill_type_id = ? AND is_active = 1", [$l['id'], $rent['id']]) as $c) {
                $new = round2((float)$c['amount'] * (1 + (float)$l['escalation_pct'] / 100));
                q("UPDATE bln_lease_charges SET amount = ? WHERE id = ?", [$new, $c['id']]);
                q("UPDATE bln_leases SET rent_amount = ?, next_escalation_date = DATE_ADD(next_escalation_date, INTERVAL escalation_every_months MONTH) WHERE id = ?", [$new, $l['id']]);
                audit('billing', 'escalate', 'lease', $l['id'], "Rent escalated {$l['escalation_pct']}% to " . money($new));
                foreach (col("SELECT party_id FROM bln_lease_parties WHERE lease_id = ? AND role IN ('tenant','co_tenant')", [$l['id']]) as $pid) portal_notify((int)$pid, 'lease', 'Rent review', 'Your rent changes to ' . money($new) . ' per your lease terms.', 'fa-arrow-trend-up');
                $n++;
            }
        }
        return "$n rent escalation(s)";
    },
    'leases' => function () {
        $n = 0; foreach (all("SELECT id FROM bln_leases WHERE status = 'notice' AND end_date < CURDATE()") as $l) { lease_end((int)$l['id'], date('Y-m-d', strtotime('-1 day')), 'Notice period ended'); $n++; }
        return "$n account(s) closed after notice";
    },
    'penalties' => fn() => run_penalties() . ' penalty invoice(s)',
    'reminders' => fn() => run_reminders() . ' reminder(s)',
    'campaigns' => function () { $n = 0; foreach (col("SELECT id FROM com_campaigns WHERE status = 'scheduled' AND scheduled_at <= NOW()") as $id) { campaign_send((int)$id); $n++; } return "$n scheduled campaign(s) sent"; },
    'coop' => fn() => coop_pull_transactions(2),
    'ical' => function () { $n = 0; foreach (col("SELECT id FROM sst_channels WHERE is_active = 1 AND ical_import_url IS NOT NULL AND ical_import_url <> '' AND (last_synced_at IS NULL OR last_synced_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE))") as $cid) { try { $n += sst_ical_import((int)$cid); } catch (Throwable $e) { error_log((string)$e); } } return "$n OTA event(s) imported"; },
    'maintenance' => fn() => crm_run_maintenance() . ' maintenance ticket(s)',
    'insights' => function () { if ((int)date('G') !== 6 || val("SELECT 1 FROM rpt_ai_insights WHERE scope = 'portfolio' AND DATE(created_at) = CURDATE()")) return 'skipped'; generate_insights('portfolio', null, true); return 'daily briefing generated'; },
    'cleanup' => function () { q("UPDATE sys_sessions SET ended_at = last_activity WHERE ended_at IS NULL AND last_activity < DATE_SUB(NOW(), INTERVAL 12 HOUR)"); q("DELETE FROM sys_rate_limits WHERE window_ends < NOW()"); return 'ok'; },
];
foreach ($jobs as $name => $fn) {
    if ($want && !in_array($name, $want, true)) continue;
    try { $log($name, $fn()); } catch (Throwable $e) { $log($name, 'ERROR ' . $e->getMessage()); error_log("[cron $name] " . $e); }
}
