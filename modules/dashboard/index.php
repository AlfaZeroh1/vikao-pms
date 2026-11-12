<?php
$title = 'Good ' . (date('H') < 12 ? 'morning' : (date('H') < 17 ? 'afternoon' : 'evening')) . ', ' . $me['first_name'];
$subtitle = date('l, j F Y') . ' · ' . e($me['role_name']);
$sc = scope_sql('property_id');

/* ---- meter readers get a focused dashboard ---- */
if (!can('billing.invoices.view') && can('property.readings.capture')) {
    $tasks = all("SELECT a.*, p.name AS property, (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id) total,
                  (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id AND i.status <> 'pending') done
                  FROM prpt_reading_assignments a JOIN prpt_properties p ON p.id = a.property_id WHERE a.assigned_to = ? AND a.status IN ('assigned','in_progress') ORDER BY a.due_date", [$me['id']]); ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
      <?php foreach ($tasks as $t): $pct = $t['total'] ? round($t['done'] / $t['total'] * 100) : 0; ?>
        <a href="<?= e(page('property/field-reading', ['assignment' => $t['id']])) ?>" class="vk-panel p-5 hover:border-accent transition">
          <div class="flex items-center gap-2"><?= pill($t['status']) ?><span class="ml-auto text-xs text-muted">Due <?= fdate($t['due_date']) ?></span></div>
          <p class="mt-3 font-bold text-lg"><?= e($t['title']) ?></p><p class="text-sm text-muted"><?= e($t['property']) ?> · <?= $t['done'] ?>/<?= $t['total'] ?> meters</p>
          <div class="vk-kpi-bar mt-3"><span style="width:<?= $pct ?>%"></span></div>
          <span class="vk-btn-accent w-full mt-4"><i class="fa-solid fa-gauge-high"></i>Start reading</span>
        </a>
      <?php endforeach; if (!$tasks) echo '<div class="vk-panel sm:col-span-3">' . ui_empty('fa-mug-hot', 'No assignments right now', 'New meter reading assignments will appear here.') . '</div>'; ?>
    </div>
    <?php return;
}

$k = portfolio_kpis();
$months = []; for ($i = 11; $i >= 0; $i--) $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
$inv = []; foreach (all("SELECT DATE_FORMAT(issue_date, '%Y-%m') m, SUM(total) t FROM bln_invoices WHERE status NOT IN ('void','draft') AND issue_date >= ? AND $sc GROUP BY m", [$months[0] . '-01']) as $r) $inv[$r['m']] = (float)$r['t'];
$col = []; foreach (all("SELECT DATE_FORMAT(paid_at, '%Y-%m') m, SUM(amount) t FROM fin_payments WHERE status <> 'reversed' AND paid_at >= ? AND " . scope_sql('COALESCE(property_id, 0)') . " GROUP BY m", [$months[0] . '-01']) as $r) $col[$r['m']] = (float)$r['t'];
$recentPay = all("SELECT p.*, pt.display_name, a.name AS account FROM fin_payments p LEFT JOIN prpt_parties pt ON pt.id = p.party_id JOIN fin_accounts a ON a.id = p.account_id ORDER BY p.paid_at DESC LIMIT 7");
$debtors = arrears_query([], true);
$myTickets = all("SELECT t.*, c.name AS cat, c.icon FROM crm_tickets t JOIN crm_categories c ON c.id = t.category_id WHERE t.status NOT IN ('resolved','closed','cancelled') AND " . scope_sql('t.property_id') . " ORDER BY (t.assigned_to = ?) DESC, t.sla_due_at < NOW() DESC, FIELD(t.priority,'urgent','high','medium','low'), t.created_at LIMIT 6", [$me['id']]);
$props = all("SELECT p.*, (SELECT COUNT(*) FROM prpt_units u WHERE u.property_id = p.id AND u.deleted_at IS NULL) units,
    (SELECT COUNT(*) FROM prpt_units u WHERE u.property_id = p.id AND u.deleted_at IS NULL AND u.status IN ('occupied','owner_occupied')) occ,
    (SELECT COALESCE(SUM(balance),0) FROM bln_invoices i WHERE i.property_id = p.id AND i.status IN ('issued','partially_paid','overdue') AND i.due_date < CURDATE()) arrears
    FROM prpt_properties p WHERE p.deleted_at IS NULL AND " . scope_sql('p.id') . " ORDER BY p.name LIMIT 8");
$insights = array_slice(insights_rules($k), 0, 4);
$actions = '<a href="' . e(page('billing/runs')) . '" class="vk-btn-ghost"><i class="fa-solid fa-wand-magic-sparkles"></i>Run billing</a><a href="' . e(page('finance/consume')) . '" class="vk-btn-accent"><i class="fa-solid fa-arrows-split-up-and-left"></i>Consume payments</a>';
?>
<div class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-3 mb-6">
  <?= ui_stat('fa-door-open', 'Units · ' . $k['occupancy_pct'] . '% occupied', num($k['units']), 'blue', $k['vacant'] . ' vacant', page('property/units')) ?>
  <?= ui_stat('fa-file-invoice', 'Invoiced this month', money($k['invoiced_this_month'], true, true), 'accent', null, page('billing/invoices')) ?>
  <?= ui_stat('fa-money-bill-trend-up', 'Collected this month', money($k['collected_this_month'], true, true), 'green', null, page('finance/payments')) ?>
  <?= ui_stat('fa-triangle-exclamation', 'Overdue arrears', money($k['arrears_total'], true, true), 'red', null, page('billing/arrears')) ?>
  <?= ui_stat('fa-hourglass-half', 'Unconsumed payments', money($k['unallocated_payments'], true, true), $k['unallocated_count'] ? 'violet' : 'slate', $k['unallocated_count'] . ' pending', page('finance/payments', ['status' => 'unallocated'])) ?>
  <?= ui_stat('fa-ticket', 'Open tickets', num($k['open_tickets']), $k['sla_breached'] ? 'red' : 'slate', $k['sla_breached'] . ' SLA breached', page('crm/tickets')) ?>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
  <div class="vk-panel p-5 xl:col-span-2">
    <div class="flex items-center mb-4"><h2 class="font-bold">Billing vs collections — last 12 months</h2><span class="ml-auto text-xs text-muted">Collection rate last month: <b class="text-ink"><?= $k['collection_rate_last_month'] ?? '—' ?>%</b></span></div>
    <div class="h-72"><canvas id="ch-coll"></canvas></div>
  </div>
  <div class="vk-panel p-5">
    <div class="flex items-center mb-3"><h2 class="font-bold flex items-center gap-2"><i class="fa-solid fa-wand-magic-sparkles text-violet-500"></i>Insights</h2><a href="<?= e(page('reports/insights')) ?>" class="ml-auto text-xs font-bold text-accent">AI briefing →</a></div>
    <ul class="space-y-3">
      <?php foreach ($insights as $in): $c = ['critical' => 'red', 'warning' => 'amber', 'info' => 'blue', 'good' => 'green'][$in['sev']]; ?>
        <li class="flex gap-3"><span class="mt-0.5 grid h-7 w-7 shrink-0 place-items-center rounded-lg <?= PILL_TONES[$c] ?>"><i class="fa-solid <?= ['critical' => 'fa-circle-exclamation', 'warning' => 'fa-triangle-exclamation', 'info' => 'fa-circle-info', 'good' => 'fa-circle-check'][$in['sev']] ?> text-xs"></i></span>
          <div><p class="text-sm font-semibold leading-snug"><?php if ($in['link']): ?><a href="<?= e($in['link']) ?>" class="hover:text-accent"><?= e($in['title']) ?></a><?php else: ?><?= e($in['title']) ?><?php endif; ?></p><p class="text-xs text-muted"><?= e($in['action']) ?></p></div></li>
      <?php endforeach; ?>
    </ul>
    <div class="mt-5 grid grid-cols-4 gap-2 text-center">
      <?php foreach ([['1-30', $k['aging_1_30']], ['31-60', $k['aging_31_60']], ['61-90', $k['aging_61_90']], ['90+', $k['aging_91_99999']]] as [$l, $v]): ?>
        <div class="rounded-xl bg-surface2 p-2"><p class="text-[10px] font-bold text-muted"><?= $l ?> days</p><p class="text-sm font-extrabold"><?= money($v, false, true) ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mt-6">
  <div class="vk-panel overflow-hidden xl:col-span-2">
    <div class="flex items-center px-5 py-4 border-b border-line"><h2 class="font-bold">Recent payments</h2><a href="<?= e(page('finance/payments')) ?>" class="ml-auto text-xs font-bold text-accent">All payments →</a></div>
    <table class="vk-table"><thead><tr><th>Receipt</th><th>From</th><th>Account</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
      <?php foreach ($recentPay as $p): ?>
        <tr data-href="<?= e(page('finance/consume', ['payment' => $p['uuid']])) ?>"><td><b><?= e($p['receipt_no']) ?></b><span class="block text-xs text-muted"><?= e($p['reference'] ?: strtoupper($p['method'])) ?> · <?= time_ago($p['paid_at']) ?></span></td>
          <td><?= e($p['display_name'] ?: ($p['payer_name'] ?: '— unmatched')) ?></td><td class="text-xs"><?= e($p['account']) ?></td><td><?= pill($p['status']) ?></td><td class="num"><?= ui_money_cell($p['amount']) ?></td></tr>
      <?php endforeach; if (!$recentPay) echo '<tr><td colspan="5">' . ui_empty('fa-money-bill', 'No payments yet') . '</td></tr>'; ?>
    </tbody></table>
  </div>
  <div class="vk-panel p-5">
    <div class="flex items-center mb-3"><h2 class="font-bold">Occupancy</h2><span class="ml-auto text-2xl font-extrabold"><?= $k['occupancy_pct'] ?>%</span></div>
    <div class="h-48"><canvas id="ch-occ"></canvas></div>
  </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mt-6">
  <div class="vk-panel overflow-hidden">
    <div class="flex items-center px-5 py-4 border-b border-line"><h2 class="font-bold">Top arrears</h2><a href="<?= e(page('billing/arrears')) ?>" class="ml-auto text-xs font-bold text-accent">Arrears →</a></div>
    <ul class="divide-y divide-line/60">
      <?php foreach (array_slice($debtors, 0, 6) as $d): ?>
        <li class="flex items-center gap-3 px-5 py-3"><?= ui_avatar($d['display_name']) ?><div class="min-w-0 flex-1"><a href="<?= e(page('property/party', ['id' => $d['party_id']])) ?>" class="font-semibold text-sm truncate block hover:text-accent"><?= e($d['display_name']) ?></a><p class="text-xs text-muted"><?= e(($d['unit_no'] ?? '—') . ' · ' . $d['property_name']) ?> · since <?= fdate($d['oldest_due'], 'j M') ?></p></div><span class="font-bold text-rose-600 text-sm"><?= money($d['arrears'], false, true) ?></span></li>
      <?php endforeach; if (!$debtors) echo ui_empty('fa-face-smile', 'No arrears 🎉'); ?>
    </ul>
  </div>
  <div class="vk-panel overflow-hidden">
    <div class="flex items-center px-5 py-4 border-b border-line"><h2 class="font-bold">Tickets needing attention</h2><a href="<?= e(page('crm/tickets')) ?>" class="ml-auto text-xs font-bold text-accent">CRM →</a></div>
    <ul class="divide-y divide-line/60">
      <?php foreach ($myTickets as $t): ?>
        <li class="px-5 py-3"><a href="<?= e(page('crm/ticket', ['id' => $t['id']])) ?>" class="flex items-center gap-3"><span class="grid h-9 w-9 place-items-center rounded-xl bg-surface2 text-accent"><i class="fa-solid <?= e($t['icon']) ?>"></i></span>
          <span class="min-w-0 flex-1"><span class="block font-semibold text-sm truncate"><?= e($t['subject']) ?></span><span class="text-xs text-muted"><?= e($t['ticket_no']) ?> · <?= time_ago($t['created_at']) ?></span></span><?= pill($t['priority']) ?></a></li>
      <?php endforeach; if (!$myTickets) echo ui_empty('fa-mug-hot', 'Inbox zero'); ?>
    </ul>
  </div>
  <div class="vk-panel overflow-hidden">
    <div class="flex items-center px-5 py-4 border-b border-line"><h2 class="font-bold">Properties</h2><a href="<?= e(page('property/properties')) ?>" class="ml-auto text-xs font-bold text-accent">All →</a></div>
    <ul class="divide-y divide-line/60">
      <?php foreach ($props as $p): $occ = $p['units'] ? round($p['occ'] / $p['units'] * 100) : 0; ?>
        <li class="px-5 py-3"><a href="<?= e(page('property/property', ['id' => $p['id']])) ?>" class="block">
          <div class="flex items-center gap-2"><b class="text-sm"><?= e($p['name']) ?></b><span class="ml-auto text-xs text-muted"><?= $p['occ'] ?>/<?= $p['units'] ?> occupied</span></div>
          <div class="vk-kpi-bar mt-1.5"><span style="width:<?= $occ ?>%"></span></div>
          <?php if ($p['arrears'] > 0): ?><p class="text-[11px] text-rose-600 mt-1"><?= money($p['arrears']) ?> overdue</p><?php endif; ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php ob_start(); ?>
<script>
VK.chart('ch-coll', { type: 'bar', data: { labels: <?= json_encode(array_map(fn($m) => date('M y', strtotime($m . '-01')), $months)) ?>, datasets: [
  { label: 'Invoiced', data: <?= json_encode(array_map(fn($m) => $inv[$m] ?? 0, $months)) ?>, backgroundColor: 'rgba(30,41,59,.85)', borderRadius: 6 },
  { label: 'Collected', data: <?= json_encode(array_map(fn($m) => $col[$m] ?? 0, $months)) ?>, backgroundColor: '#D97706', borderRadius: 6 }] },
  options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { ticks: { callback: v => (v >= 1e6 ? (v / 1e6) + 'M' : v >= 1e3 ? (v / 1e3) + 'K' : v) } } } } });
VK.chart('ch-occ', { type: 'doughnut', data: { labels: ['Occupied', 'Vacant', 'Other'], datasets: [{ data: [<?= $k['occupied'] ?>, <?= $k['vacant'] ?>, <?= max(0, $k['units'] - $k['occupied'] - $k['vacant']) ?>], backgroundColor: ['#10B981', '#F59E0B', '#94A3B8'], borderWidth: 0 }] }, options: { maintainAspectRatio: false, cutout: '72%', plugins: { legend: { position: 'right' } } } });
</script>
<?php page_script(ob_get_clean()); ?>
