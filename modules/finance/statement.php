<?php
$type = (string)($_GET['type'] ?? 'party'); $id = (int)($_GET['id'] ?? 0);
$f = ['from' => input_date('from', in_array($type, ['property'], true) ? date('Y-m-01') : date('Y-01-01')), 'to' => input_date('to', date('Y-m-d')), 'bill_type_id' => (int)($_GET['bill_type_id'] ?? 0) ?: null, 'unit_id' => (int)($_GET['unit_id'] ?? 0) ?: null, 'property_id' => (int)($_GET['property_id'] ?? 0) ?: null];
$st = statement_render($type, $id, $f);
if (!empty($_GET['print'])) { $layout = 'print'; $title = $st['title']; echo $st['html']; return; }
if (($_GET['export'] ?? '') === 'csv' && in_array($type, ['party', 'lease'], true)) {
    $s = statement_party($type === 'lease' ? (int)lease_primary_payer($id) : $id, $f + ($type === 'lease' ? ['lease_id' => $id] : []));
    csv_download('statement-' . $type . '-' . $id . '.csv', ['Date', 'Reference', 'Details', 'Debit', 'Credit', 'Balance'], array_merge([['', 'Opening balance', '', '', '', $s['opening']]], array_map(fn($r) => [$r['d'], $r['ref'], $r['descr'], $r['debit'], $r['credit'], $r['balance']], $s['rows'])));
}
$title = $st['title'];
$crumbs = [['Statements', page('finance/statements')]];
$partyId = in_array($type, ['party', 'landlord'], true) ? $id : null;
$a = ['<a class="vk-btn-ghost" target="_blank" href="' . e('?' . http_build_query($_GET + ['print' => 1])) . '"><i class="fa-solid fa-print"></i>Print / PDF</a>'];
if (in_array($type, ['party', 'lease'], true)) $a[] = '<a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>CSV</a>';
if ($partyId && can('comms.send')) $a[] = '<button class="vk-btn-accent" data-action="' . e(url('api.php', ['r' => 'comms/send-statement'])) . '" data-payload=\'' . e(json_encode(['type' => $type, 'id' => $id] + array_filter($f))) . '\' data-confirm="Email this statement and share it in the portal?" data-icon="question" data-no-reload><i class="fa-solid fa-paper-plane"></i>Send</button>';
$actions = implode('', $a);
?>
<form method="get" class="vk-panel p-3 mb-4 flex flex-wrap items-end gap-2" data-autosubmit>
  <?php foreach (['r', 'type', 'id', 'unit_id', 'property_id'] as $k) if (!empty($_GET[$k])) echo '<input type="hidden" name="' . $k . '" value="' . e($_GET[$k]) . '">'; ?>
  <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
  <?php if ($type !== 'landlord'): ?><?= ui_field('Bill type', ui_select('bill_type_id', ui_options(bill_types_for_select(), 'id', 'name', $f['bill_type_id'], 'All bills')), 'w-48') ?><?php endif; ?>
  <?php if ($type === 'party'): $units = all("SELECT DISTINCT u.id, u.unit_no AS name FROM bln_invoices i JOIN prpt_units u ON u.id = i.unit_id WHERE i.party_id = ?", [$id]); if (count($units) > 1): ?><?= ui_field('Unit', ui_select('unit_id', ui_options($units, 'id', 'name', $f['unit_id'], 'All units')), 'w-40') ?><?php endif; endif; ?>
  <div class="flex gap-1 pb-0.5"><?php foreach (['This month' => [date('Y-m-01'), date('Y-m-d')], 'Last month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))], 'This year' => [date('Y-01-01'), date('Y-m-d')], 'Last 12 mo' => [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')]] as $l => [$a1, $b1]): ?><a class="vk-chip !py-1.5 text-xs" href="<?= e('?' . http_build_query(['from' => $a1, 'to' => $b1] + $_GET)) ?>"><?= $l ?></a><?php endforeach; ?></div>
</form>
<div class="vk-doc max-w-5xl border border-line overflow-x-auto"><?= $st['html'] ?></div>
