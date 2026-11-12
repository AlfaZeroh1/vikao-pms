<?php
require_once __DIR__ . '/_catalogue.php';
$cat = report_catalogue(); $key = (string)($_GET['key'] ?? '');
if (!isset($cat[$key])) { echo ui_empty('fa-chart-pie', 'Unknown report'); return; }
[$rt, $ic, $sec, $desc, $fn] = $cat[$key];
$f = ['property_id' => (int)($_GET['property_id'] ?? 0), 'from' => input_date('from', date('Y-01-01')), 'to' => input_date('to', date('Y-m-d'))];
$r = $fn($f);
$fmt = function ($v, $t) { return match ($t) { 'money' => money($v, false), 'num' => is_numeric($v) ? num($v, floor((float)$v) == (float)$v ? 0 : 2) : e((string)$v), 'pct' => $v === null ? '—' : num($v, 1) . '%', 'date' => fdate($v), default => e(str_replace('_', ' ', (string)$v)) }; };
if (($_GET['export'] ?? '') === 'csv') csv_download($key . '-' . date('Ymd') . '.csv', array_map(fn($c) => $c[0], $r['cols']), array_map(fn($row) => array_map(fn($k) => $row[$k] ?? '', array_keys($r['cols'])), $r['rows']));
$tot = []; foreach ($r['totals'] ?? [] as $k) $tot[$k] = array_sum(array_map(fn($x) => (float)($x[$k] ?? 0), $r['rows']));
$table = function () use ($r, $fmt, $tot) { ob_start(); ?>
  <table class="vk-table"><thead><tr><?php foreach ($r['cols'] as $c) echo '<th class="' . (in_array($c[1] ?? 'text', ['money', 'num', 'pct'], true) ? 'num' : '') . '">' . e($c[0]) . '</th>'; ?></tr></thead><tbody>
  <?php foreach ($r['rows'] as $row): ?><tr><?php foreach ($r['cols'] as $k => $c) echo '<td class="' . (in_array($c[1] ?? 'text', ['money', 'num', 'pct'], true) ? 'num' : '') . '">' . $fmt($row[$k] ?? null, $c[1] ?? 'text') . '</td>'; ?></tr><?php endforeach; ?>
  </tbody><?php if ($tot): ?><tfoot><tr class="tot font-bold"><?php $i = 0; foreach ($r['cols'] as $k => $c) { echo '<td class="' . (isset($tot[$k]) ? 'num' : '') . '">' . (isset($tot[$k]) ? $fmt($tot[$k], $c[1] ?? 'num') : ($i === 0 ? 'Total' : '')) . '</td>'; $i++; } ?></tr></tfoot><?php endif; ?></table>
<?php return ob_get_clean(); };
if (!empty($_GET['print'])) { $layout = 'print'; $title = $rt; echo '<h1>' . e($rt) . '</h1><p class="muted">' . fdate($f['from']) . ' – ' . fdate($f['to']) . ($f['property_id'] ? ' · ' . e((string)val("SELECT name FROM prpt_properties WHERE id = ?", [$f['property_id']])) : ' · All properties') . '</p>' . $table(); return; }
$title = $rt; $subtitle = e($desc); $crumbs = [['Report centre', page('reports/index')]];
$actions = '<a class="vk-btn-ghost" target="_blank" href="' . e('?' . http_build_query($_GET + ['print' => 1])) . '"><i class="fa-solid fa-print"></i>Print / PDF</a><a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>CSV</a>';
?>
<?= ui_filter_open() ?>
  <input type="hidden" name="key" value="<?= e($key) ?>">
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All properties')), 'w-56') ?>
  <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
<?= ui_filter_close() ?>
<?php if (!empty($r['chart']) && $r['rows']): ?><div class="vk-panel p-5 mb-5"><div class="h-72"><canvas id="rchart"></canvas></div></div><?php endif; ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><?= $table() ?></div><?= $r['rows'] ? '' : ui_empty($ic, 'No data for these filters') ?></div>
<?php if (!empty($r['chart']) && $r['rows']) { $c = $r['chart']; $pal = ['#D97706', '#0EA5E9', '#10B981', '#8B5CF6', '#F43F5E', '#64748B', '#14B8A6', '#EAB308'];
  $ds = []; $i = 0; foreach ($c['series'] as $name => $data) $ds[] = ['label' => $name, 'data' => array_values($data), 'backgroundColor' => $c['type'] === 'doughnut' ? $pal : $pal[$i++ % 8], 'borderRadius' => $c['type'] === 'bar' ? 6 : 0];
  page_script('<script>VK.chart("rchart",{type:' . json_encode($c['type']) . ',data:{labels:' . json_encode(array_values($c['labels'])) . ',datasets:' . json_encode($ds) . '},options:{maintainAspectRatio:false,indexAxis:' . json_encode(!empty($c['horizontal']) ? 'y' : 'x') . ',plugins:{legend:{position:"bottom",display:' . (count($ds) > 1 || $c['type'] === 'doughnut' ? 'true' : 'false') . '}},scales:' . ($c['type'] === 'doughnut' ? '{}' : '{x:{stacked:' . (!empty($c['stacked']) ? 'true' : 'false') . '},y:{stacked:' . (!empty($c['stacked']) ? 'true' : 'false') . '}}') . '}})</script>'); }
