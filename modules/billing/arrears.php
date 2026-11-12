<?php
$title = 'Arrears';
$subtitle = 'Filter by house, block, bill type or date — click any amount paid to see the payment history';
$f = ['property_id' => (int)($_GET['property_id'] ?? 0), 'block_id' => (int)($_GET['block_id'] ?? 0), 'unit_id' => (int)($_GET['unit_id'] ?? 0), 'bill_type_id' => (int)($_GET['bill_type_id'] ?? 0),
      'party_id' => (int)($_GET['party_id'] ?? 0), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'overdue_only' => !empty($_GET['overdue_only'])];
$view = $_GET['view'] ?? 'summary';
$blocks = $f['property_id'] ? all("SELECT id, CONCAT(code, ' — ', name) AS name FROM prpt_blocks WHERE property_id = ?", [$f['property_id']]) : [];
$rows = arrears_query($f, $view === 'summary');
$lines = $view === 'summary' ? arrears_query($f) : $rows;
$aging = ['current' => 0, '1-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0]; $byType = [];
foreach ($lines as $l) { $d = (int)$l['days_overdue']; $k = $d <= 0 ? 'current' : ($d <= 30 ? '1-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'))); $aging[$k] += (float)$l['arrears']; $byType[$l['bill_type']] = ($byType[$l['bill_type']] ?? 0) + (float)$l['arrears']; }
$total = array_sum($aging);
if (($_GET['export'] ?? '') === 'csv') {
    if ($view === 'summary') csv_download('arrears-summary-' . date('Ymd') . '.csv', ['Customer', 'Phone', 'Unit', 'Block', 'Property', 'Invoiced', 'Paid', 'Arrears', 'Oldest due', 'Invoices'], array_map(fn($r) => [$r['display_name'], $r['phone'], $r['unit_no'], $r['block_code'], $r['property_name'], $r['invoiced'], $r['paid'], $r['arrears'], $r['oldest_due'], $r['invoices']], $rows));
    csv_download('arrears-detail-' . date('Ymd') . '.csv', ['Invoice', 'Issued', 'Due', 'Days overdue', 'Customer', 'Unit', 'Block', 'Property', 'Bill', 'Description', 'Billed', 'Paid', 'Arrears'], array_map(fn($r) => [$r['invoice_no'], $r['issue_date'], $r['due_date'], $r['days_overdue'], $r['display_name'], $r['unit_no'], $r['block_code'], $r['property_name'], $r['bill_type'], $r['description'], $r['line_total'], $r['amount_paid'], $r['arrears']], $rows));
}
$actions = '<a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export</a>' . (can('comms.send') ? '<a class="vk-btn-accent" href="' . e(page('comms/compose', ['audience' => 'arrears', 'property_id' => $f['property_id'] ?: null, 'template' => 'arrears_reminder'])) . '"><i class="fa-solid fa-bell"></i>Remind all</a>' : '');
$q = fn($x) => json_encode(array_filter(['from' => $f['from'] ?: null, 'to' => $f['to'] ?: null, 'bill_type_id' => $f['bill_type_id'] ?: null] + $x));
?>
<div class="grid grid-cols-1 lg:grid-cols-[1fr_1.2fr] gap-4 mb-5">
  <div class="vk-panel p-5"><p class="text-sm text-muted font-semibold">Total arrears</p><p class="text-3xl font-extrabold tracking-tight text-rose-600"><?= money($total) ?></p>
    <div class="mt-4 flex h-3 rounded-full overflow-hidden bg-surface2"><?php $cols = ['current' => '#94A3B8', '1-30' => '#F59E0B', '31-60' => '#F97316', '61-90' => '#EF4444', '90+' => '#991B1B']; foreach ($aging as $k => $v) if ($v > 0) echo '<span title="' . $k . ': ' . money($v) . '" style="width:' . ($v / max($total, 1) * 100) . '%;background:' . $cols[$k] . '"></span>'; ?></div>
    <div class="mt-3 grid grid-cols-5 gap-2 text-center text-xs"><?php foreach ($aging as $k => $v): ?><div><span class="inline-block h-2 w-2 rounded-full" style="background:<?= $cols[$k] ?>"></span> <span class="text-muted"><?= $k === 'current' ? 'Not due' : $k . ' d' ?></span><b class="block"><?= money($v, false, true) ?></b></div><?php endforeach; ?></div></div>
  <div class="vk-panel p-5"><p class="text-sm text-muted font-semibold mb-2">By bill type</p>
    <?php arsort($byType); foreach (array_slice($byType, 0, 6, true) as $k => $v): ?><div class="flex items-center gap-3 text-sm mb-1.5"><span class="w-32 truncate"><?= e($k) ?></span><div class="flex-1 h-2 rounded-full bg-surface2 overflow-hidden"><div class="h-full bg-accent" style="width:<?= $v / max($total, 1) * 100 ?>%"></div></div><b class="w-28 text-right tabular-nums"><?= money($v, false) ?></b></div><?php endforeach; if (!$byType) echo '<p class="text-sm text-muted">No arrears 🎉</p>'; ?></div>
</div>
<?= ui_filter_open() ?>
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All')), 'w-48') ?>
  <?= ui_field('Block', ui_select('block_id', ui_options($blocks, 'id', 'name', $f['block_id'], $f['property_id'] ? 'All blocks' : 'Choose property')), 'w-40') ?>
  <?= ui_field('House / unit', ui_unit_picker('unit_id', $f['unit_id'] ?: null), 'w-48') ?>
  <?= ui_field('Customer', ui_party_picker('party_id', $f['party_id'] ?: null), 'w-48') ?>
  <?= ui_field('Bill type', ui_select('bill_type_id', ui_options(bill_types_for_select(), 'id', 'name', $f['bill_type_id'], 'All bills')), 'w-40') ?>
  <?= ui_field('Invoiced from', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('to', ui_input('to', $f['to'], 'date'), 'w-40') ?>
  <label class="vk-switch pb-3"><input type="checkbox" name="overdue_only" value="1" <?= $f['overdue_only'] ? 'checked' : '' ?>>Overdue only</label>
<?= ui_filter_close() ?>
<div class="flex gap-1 mb-3"><?php foreach (['summary' => ['By house / customer', 'fa-users'], 'detail' => ['Every bill line', 'fa-list']] as $k => [$l, $ic]): ?><a href="<?= e('?' . http_build_query(['view' => $k] + $_GET)) ?>" class="vk-chip <?= $view === $k ? '!border-accent !bg-accent/10' : '' ?>"><i class="fa-solid <?= $ic ?>"></i><?= $l ?></a><?php endforeach; ?></div>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
<?php if ($view === 'summary'): ?>
  <thead><tr><th>Customer</th><th>House</th><th>Property</th><th>Oldest due</th><th class="num">Invoices</th><th class="num">Billed</th><th class="num">Amount paid</th><th class="num">Arrears</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><a class="font-semibold hover:text-accent" href="<?= e(page('property/party', ['id' => $r['party_id']])) ?>"><?= e($r['display_name']) ?></a><span class="block text-xs text-muted"><?= e($r['phone']) ?></span></td>
    <td><b><?= e($r['unit_no'] ?? '—') ?></b><?= $r['block_code'] ? '<span class="block text-xs text-muted">Block ' . e($r['block_code']) . '</span>' : '' ?></td><td class="text-sm"><?= e($r['property_name']) ?></td>
    <td class="text-xs <?= $r['oldest_due'] < date('Y-m-d') ? 'text-rose-600 font-semibold' : '' ?>"><?= fdate($r['oldest_due']) ?></td><td class="num"><?= (int)$r['invoices'] ?></td><td class="num"><?= money($r['invoiced'], false) ?></td>
    <td class="num"><a href="#" class="text-emerald-600 font-semibold underline decoration-dotted" data-paid='<?= e($q(['party_id' => (int)$r['party_id'], 'unit_id' => $r['unit_id'] ? (int)$r['unit_id'] : null])) ?>' data-paid-title="Payments — <?= e($r['display_name'] . ($r['unit_no'] ? ' · ' . $r['unit_no'] : '')) ?>"><?= money($r['paid'], false) ?></a></td>
    <td class="num font-extrabold text-rose-600"><?= money($r['arrears'], false) ?></td>
    <td class="text-right whitespace-nowrap"><a class="vk-icon-btn !h-8 !w-8" title="Statement" href="<?= e(page('finance/statement', ['type' => 'party', 'id' => $r['party_id']])) ?>"><i class="fa-solid fa-file-lines text-xs"></i></a><?php if (can('finance.payments.create')): ?><a class="vk-icon-btn !h-8 !w-8" title="Receive payment" href="<?= e(page('finance/payments', ['new' => 1, 'party_id' => $r['party_id'], 'unit_id' => $r['unit_id']])) ?>"><i class="fa-solid fa-money-bill text-xs"></i></a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody><?php if ($rows): ?><tfoot><tr class="font-bold"><td colspan="5">Total</td><td class="num"><?= money(array_sum(array_column($rows, 'invoiced')), false) ?></td><td class="num"><?= money(array_sum(array_column($rows, 'paid')), false) ?></td><td class="num text-rose-600"><?= money(array_sum(array_column($rows, 'arrears')), false) ?></td><td></td></tr></tfoot><?php endif; ?>
<?php else: ?>
  <thead><tr><th>Invoice</th><th>Customer</th><th>House</th><th>Bill</th><th>Due</th><th class="num">Billed</th><th class="num">Amount paid</th><th class="num">Arrears</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr data-href="<?= e(page('billing/invoice', ['id' => $r['invoice_id']])) ?>">
    <td><b><?= e($r['invoice_no']) ?></b><span class="block text-xs text-muted"><?= fdate($r['issue_date']) ?></span></td><td class="text-sm"><?= e($r['display_name']) ?></td><td><?= e($r['unit_no'] ?? '—') ?><?= $r['block_code'] ? ' <span class="text-xs text-muted">Blk ' . e($r['block_code']) . '</span>' : '' ?></td>
    <td><span class="inline-block h-2 w-2 rounded-full mr-1" style="background:<?= e($r['color_hex']) ?>"></span><?= e($r['bill_type']) ?><span class="block text-[11px] text-muted truncate max-w-[220px]"><?= e($r['description']) ?></span></td>
    <td class="text-xs"><?= fdate($r['due_date']) ?><?= $r['days_overdue'] > 0 ? '<span class="block text-rose-600 font-semibold">' . (int)$r['days_overdue'] . ' days</span>' : '' ?></td><td class="num"><?= money($r['line_total'], false) ?></td>
    <td class="num"><?= (float)$r['amount_paid'] > 0 ? '<a href="#" class="text-emerald-600 font-semibold underline decoration-dotted" data-paid=\'{"line_id":' . (int)$r['line_id'] . '}\'>' . money($r['amount_paid'], false) . '</a>' : '<span class="text-muted">0.00</span>' ?></td><td class="num font-bold text-rose-600"><?= money($r['arrears'], false) ?></td></tr><?php endforeach; ?>
  </tbody>
<?php endif; ?>
</table></div><?= $rows ? '' : ui_empty('fa-face-smile', 'No arrears for these filters') ?></div>
<?php page_script('<script>$("[name=property_id]").on("change",function(){$("[name=block_id]").val("")})</script>');
