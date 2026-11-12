<?php
$title = 'AI insights';
$subtitle = 'What needs attention across the portfolio — generated from live data, explained in plain language';
$pid = (int)($_GET['property_id'] ?? 0);
$last = one("SELECT i.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS by_name FROM rpt_ai_insights i LEFT JOIN sys_users u ON u.id = i.generated_by WHERE scope = ? ORDER BY id DESC LIMIT 1", [$pid ? 'property:' . $pid : 'portfolio']);
if (!$last || !empty($_GET['refresh']) || strtotime($last['created_at']) < time() - 6 * 3600) { $g = generate_insights($pid ? 'property:' . $pid : 'portfolio', $pid ?: null, can('reports.ai')); $last = one("SELECT i.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS by_name FROM rpt_ai_insights i LEFT JOIN sys_users u ON u.id = i.generated_by WHERE i.id = ?", [$g['id']]); }
$ins = json_decode((string)$last['insights'], true) ?: [];
$k = portfolio_kpis($pid ?: null);
$hist = all("SELECT id, engine, created_at FROM rpt_ai_insights WHERE scope = ? ORDER BY id DESC LIMIT 8", [$pid ? 'property:' . $pid : 'portfolio']);
$tone = ['critical' => ['red', 'fa-circle-exclamation', 'bg-rose-500/10 text-rose-600'], 'warning' => ['amber', 'fa-triangle-exclamation', 'bg-amber-500/10 text-amber-600'], 'info' => ['blue', 'fa-circle-info', 'bg-sky-500/10 text-sky-600'], 'good' => ['green', 'fa-circle-check', 'bg-emerald-500/10 text-emerald-600']];
$ai = integration('anthropic'); $llmLive = $ai && $ai['is_active'] && !$ai['simulate'];
$actions = '<a class="vk-btn-accent" href="' . e(page('reports/insights', array_filter(['property_id' => $pid ?: null, 'refresh' => 1]))) . '"><i class="fa-solid fa-rotate"></i>Regenerate</a>';
?>
<div class="flex flex-wrap items-center gap-3 mb-5">
  <form method="get" class="flex items-center gap-2"><input type="hidden" name="r" value="reports/insights"><select name="property_id" class="vk-select !w-56 !py-2" onchange="this.form.submit()"><?= ui_options(properties_for_select(), 'id', 'name', $pid, 'Whole portfolio') ?></select></form>
  <span class="text-xs text-muted"><i class="fa-solid <?= $last['engine'] === 'llm' ? 'fa-wand-magic-sparkles text-violet-500' : 'fa-gears' ?>"></i> <?= $last['engine'] === 'llm' ? 'AI (' . e($last['model']) . ')' : 'Rules engine' ?> · generated <?= time_ago($last['created_at']) ?><?= $last['by_name'] ? ' by ' . e($last['by_name']) : '' ?></span>
  <?php if (!$llmLive): ?><span class="text-xs text-muted">· connect the Anthropic integration for narrative AI briefings</span><?php endif; ?>
</div>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-6 items-start">
  <div class="space-y-3">
  <?php foreach ($ins as $i): $sev = $i['sev'] ?? $i['severity'] ?? 'info'; [$tn, $icn, $cls] = $tone[$sev] ?? $tone['info']; ?>
    <div class="vk-panel p-5 flex gap-4"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl <?= $cls ?>"><i class="fa-solid <?= $icn ?>"></i></span>
      <div class="flex-1"><p class="font-bold"><?= e($i['title'] ?? '') ?></p><p class="text-sm text-muted mt-0.5"><?= e($i['detail'] ?? '') ?></p>
        <?php if (!empty($i['action'])): ?><p class="text-sm mt-2"><i class="fa-solid fa-arrow-right text-accent mr-1"></i><b><?= e($i['action']) ?></b></p><?php endif; ?></div>
      <?php if (!empty($i['link'])): ?><a class="self-center vk-btn-ghost !py-1.5 text-xs" href="<?= e($i['link']) ?>">Open</a><?php endif; ?></div>
  <?php endforeach; ?>
  </div>
  <aside class="space-y-4">
    <div class="vk-panel p-5"><h3 class="font-bold text-sm mb-3">Signals used</h3><dl class="text-sm space-y-1.5">
      <?php foreach ([['Occupancy', $k['occupancy_pct'] . '%'], ['Collection rate (last month)', $k['collection_rate_last_month'] !== null ? $k['collection_rate_last_month'] . '%' : '—'], ['Arrears', money($k['arrears_total'], true, true)], ['90+ days arrears', money($k['aging_91_99999'], true, true)], ['Unconsumed payments', money($k['unallocated_payments'], true, true)], ['Tickets past SLA', $k['sla_breached']], ['Leases ending ≤60 d', $k['leases_expiring_60d']], ['Short-stay occupancy (30 d)', num($k['sst_occupancy_30d'], 1) . '%'], ['Overdue sales follow-ups', $k['overdue_followups']]] as [$a, $b]): ?>
        <div class="flex justify-between"><dt class="text-muted"><?= $a ?></dt><dd class="font-semibold"><?= e((string)$b) ?></dd></div><?php endforeach; ?></dl></div>
    <?php if ($k['top_debtors']): ?><div class="vk-panel p-5"><h3 class="font-bold text-sm mb-2">Top debtors</h3><?php foreach ($k['top_debtors'] as $d): ?><p class="flex justify-between text-sm py-0.5"><span><?= e($d['display_name']) ?></span><b class="text-rose-600"><?= money($d['amt'], true, true) ?></b></p><?php endforeach; ?></div><?php endif; ?>
    <div class="vk-panel p-5"><h3 class="font-bold text-sm mb-2">History</h3><?php foreach ($hist as $h): ?><p class="text-xs text-muted py-0.5"><?= fdatetime($h['created_at']) ?> · <?= e($h['engine']) ?></p><?php endforeach; ?></div>
  </aside>
</div>
