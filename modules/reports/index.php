<?php
require_once __DIR__ . '/_catalogue.php';
$title = 'Report centre';
$subtitle = 'Every report can be filtered by property and period, charted, printed and exported to CSV';
$k = portfolio_kpis();
$cat = report_catalogue(); $by = []; foreach ($cat as $key => $r) $by[$r[2]][$key] = $r;
$actions = '<a class="vk-btn-accent" href="' . e(page('reports/insights')) . '"><i class="fa-solid fa-wand-magic-sparkles"></i>AI insights</a>';
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?= ui_stat('fa-house-circle-check', 'Occupancy', $k['occupancy_pct'] . '%', 'accent', $k['occupied'] . ' of ' . $k['units'] . ' units') ?>
  <?= ui_stat('fa-money-bill-trend-up', 'Collected this month', money($k['collected_this_month'], true, true), 'green', 'invoiced ' . money($k['invoiced_this_month'], true, true)) ?>
  <?= ui_stat('fa-triangle-exclamation', 'Arrears', money($k['arrears_total'], true, true), 'red', $k['collection_rate_last_month'] !== null ? 'last month ' . $k['collection_rate_last_month'] . '% collected' : null) ?>
  <?= ui_stat('fa-headset', 'Open tickets', num($k['open_tickets']), 'violet', $k['sla_breached'] . ' past SLA') ?>
</div>
<?php foreach ($by as $sec => $list): ?>
  <h3 class="font-bold mb-3 mt-2"><?= e($sec) ?></h3>
  <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-6">
  <?php foreach ($list as $key => [$t, $ic, , $d]): ?><a href="<?= e(page('reports/view', ['key' => $key])) ?>" class="vk-panel p-4 hover:border-accent/60 flex gap-3"><span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-accent/10 text-accent"><i class="fa-solid <?= $ic ?>"></i></span><span><b class="block text-sm"><?= e($t) ?></b><span class="text-xs text-muted"><?= e($d) ?></span></span></a><?php endforeach; ?>
  </div>
<?php endforeach; ?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3"><a class="vk-panel p-4 hover:border-accent/60 flex gap-3" href="<?= e(page('billing/arrears')) ?>"><i class="fa-solid fa-triangle-exclamation text-accent mt-1"></i><span><b class="block text-sm">Arrears (detailed)</b><span class="text-xs text-muted">Filter by house, block, bill and dates</span></span></a>
  <a class="vk-panel p-4 hover:border-accent/60 flex gap-3" href="<?= e(page('finance/statements')) ?>"><i class="fa-solid fa-file-lines text-accent mt-1"></i><span><b class="block text-sm">Statements</b><span class="text-xs text-muted">Tenant, landlord, house, property</span></span></a>
  <a class="vk-panel p-4 hover:border-accent/60 flex gap-3" href="<?= e(page('finance/quickbooks')) ?>"><i class="fa-solid fa-file-export text-accent mt-1"></i><span><b class="block text-sm">QuickBooks export</b><span class="text-xs text-muted">Accounting-ready CSV files</span></span></a></div>
