<?php
$title = 'Invoices';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'property_id' => (int)($_GET['property_id'] ?? 0), 'period' => (string)($_GET['period'] ?? ''), 'source' => (string)($_GET['source'] ?? ''),
      'bill_type_id' => (int)($_GET['bill_type_id'] ?? 0), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'unsent' => !empty($_GET['unsent'])];
$w = [scope_sql('i.property_id')]; $p = [];
if ($f['q'] !== '') { $w[] = '(i.invoice_no LIKE :q OR pt.display_name LIKE :q OR u.unit_no LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['status'] === 'open') $w[] = "i.status IN ('issued','partially_paid','overdue')"; elseif ($f['status']) { $w[] = 'i.status = :st'; $p['st'] = $f['status']; }
if ($f['property_id']) { $w[] = 'i.property_id = :pid'; $p['pid'] = $f['property_id']; }
if ($f['period']) { $w[] = 'i.period_month = :per'; $p['per'] = $f['period']; }
if ($f['source']) { $w[] = 'i.source = :src'; $p['src'] = $f['source']; }
if ($f['bill_type_id']) { $w[] = 'EXISTS (SELECT 1 FROM bln_invoice_lines x WHERE x.invoice_id = i.id AND x.bill_type_id = :bt)'; $p['bt'] = $f['bill_type_id']; }
if ($f['from']) { $w[] = 'i.issue_date >= :from'; $p['from'] = $f['from']; }
if ($f['to']) { $w[] = 'i.issue_date <= :to'; $p['to'] = $f['to']; }
if ($f['unsent']) $w[] = "i.sent_at IS NULL AND i.status NOT IN ('void','draft')";
$where = implode(' AND ', $w);
$sql = "FROM bln_invoices i JOIN prpt_parties pt ON pt.id = i.party_id JOIN prpt_properties pr ON pr.id = i.property_id LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE $where";
if (($_GET['export'] ?? '') === 'csv') csv_download('invoices-' . date('Ymd') . '.csv', ['Invoice', 'Issue date', 'Due date', 'Period', 'Customer', 'Unit', 'Property', 'Status', 'Total', 'Paid', 'Balance'],
    all("SELECT i.invoice_no, i.issue_date, i.due_date, i.period_month, pt.display_name, u.unit_no, pr.name, i.status, i.total, i.amount_paid, i.balance $sql ORDER BY i.issue_date DESC", $p));
$tot = one("SELECT COUNT(*) n, COALESCE(SUM(i.total),0) total, COALESCE(SUM(i.amount_paid),0) paid, COALESCE(SUM(i.balance),0) bal $sql AND i.status <> 'void'", $p);
[$lim, $off, $pager] = ui_paginate((int)val("SELECT COUNT(*) $sql", $p), 50);
$rows = all("SELECT i.*, pt.display_name, u.unit_no, pr.name AS property $sql ORDER BY i.issue_date DESC, i.id DESC LIMIT $lim OFFSET $off", $p);
$a = ['<a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export</a>'];
if (can('billing.runs.execute')) $a[] = '<a class="vk-btn-ghost" href="' . e(page('billing/runs')) . '"><i class="fa-solid fa-wand-magic-sparkles"></i>Billing run</a>';
if (can('billing.invoices.create')) $a[] = '<a class="vk-btn-accent" href="' . e(page('billing/invoice-edit')) . '"><i class="fa-solid fa-plus"></i>New invoice</a>';
$actions = implode('', $a);
$statuses = ['open' => 'Open (unpaid)', 'draft' => 'Draft', 'issued' => 'Issued', 'partially_paid' => 'Partially paid', 'overdue' => 'Overdue', 'paid' => 'Paid', 'void' => 'Void', 'written_off' => 'Written off'];
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
  <?= ui_stat('fa-file-invoice', 'Invoices', num($tot['n']), 'blue') ?><?= ui_stat('fa-sack-dollar', 'Invoiced', money($tot['total'], true, true), 'accent') ?>
  <?= ui_stat('fa-circle-check', 'Collected', money($tot['paid'], true, true), 'green', $tot['total'] > 0 ? round($tot['paid'] / $tot['total'] * 100) . '%' : null) ?><?= ui_stat('fa-triangle-exclamation', 'Outstanding', money($tot['bal'], true, true), 'red') ?>
</div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Invoice, name or unit"'), 'w-52') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options($statuses, $f['status'], 'Any')), 'w-40') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All')), 'w-48') ?>
  <?= ui_field('Bill type', ui_select('bill_type_id', ui_options(bill_types_for_select(), 'id', 'name', $f['bill_type_id'], 'Any')), 'w-40') ?>
  <?= ui_field('Period', ui_input('period', $f['period'], 'month'), 'w-40') ?>
  <?= ui_field('Issued from', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('to', ui_input('to', $f['to'], 'date'), 'w-40') ?>
  <?= ui_field('Source', ui_select('source', ui_kv_options(['auto' => 'Billing run', 'manual' => 'Manual', 'penalty' => 'Penalty', 'deposit' => 'Deposit', 'booking' => 'Short stay', 'sale' => 'Sale', 'opening_balance' => 'Opening balance'], $f['source'], 'Any')), 'w-36') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden">
  <?php if (can('billing.invoices.send')): ?>
  <div class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-line text-sm bg-surface2/40"><span class="text-muted"><b id="sel-count">0</b> selected</span>
    <button class="vk-btn-dark !py-1.5 text-xs" id="bulk-send"><i class="fa-solid fa-paper-plane"></i>Send by email / SMS + portal</button>
    <a class="ml-auto text-xs font-semibold text-accent" href="<?= e(page('billing/invoices', ['unsent' => 1])) ?>">Show unsent</a></div>
  <?php endif; ?>
  <div class="overflow-x-auto"><table class="vk-table">
    <thead><tr><th class="w-8"><input type="checkbox" id="sel-all" class="accent-amber-600"></th><th>Invoice</th><th>Customer</th><th>Unit</th><th>Dates</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>
    <?php foreach ($rows as $i): ?>
      <tr data-href="<?= e(page('billing/invoice', ['id' => $i['id']])) ?>">
        <td><input type="checkbox" class="row-sel accent-amber-600" value="<?= $i['id'] ?>"></td>
        <td><b><?= e($i['invoice_no']) ?></b><span class="block text-[11px] text-muted"><?= e($i['source']) ?><?= $i['period_month'] ? ' · ' . month_label($i['period_month']) : '' ?><?= $i['sent_at'] ? ' · <i class="fa-solid fa-paper-plane"></i> sent' : '' ?></span></td>
        <td class="text-sm"><?= e($i['display_name']) ?></td><td><?= e($i['unit_no'] ?? '—') ?><span class="block text-xs text-muted"><?= e($i['property']) ?></span></td>
        <td class="text-xs"><?= fdate($i['issue_date']) ?><span class="block <?= $i['balance'] > 0 && $i['due_date'] < date('Y-m-d') ? 'text-rose-600 font-semibold' : 'text-muted' ?>">due <?= fdate($i['due_date']) ?></span></td>
        <td><?= pill($i['status']) ?></td><td class="num"><?= money($i['total'], false) ?></td>
        <td class="num"><?= (float)$i['amount_paid'] > 0 ? '<a href="#" class="text-emerald-600 font-semibold underline decoration-dotted" data-paid=\'{"invoice_id":' . $i['id'] . '}\' data-paid-title="Payments on ' . e($i['invoice_no']) . '">' . money($i['amount_paid'], false) . '</a>' : '<span class="text-muted">0.00</span>' ?></td>
        <td class="num"><?= ui_money_cell($i['balance'], true) ?></td>
      </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="9">' . ui_empty('fa-file-invoice', 'No invoices match') . '</td></tr>'; ?>
    </tbody></table></div><?= $pager ?>
</div>
<?php ob_start(); ?>
<script>
$('#sel-all').on('change', function () { $('.row-sel').prop('checked', this.checked); $('#sel-count').text($('.row-sel:checked').length); });
$(document).on('change', '.row-sel', () => $('#sel-count').text($('.row-sel:checked').length));
$('#bulk-send').on('click', async function () { const ids = $('.row-sel:checked').map((i, x) => x.value).get(); if (!ids.length) return VK.toast('Select invoices first', 'warning');
  if (!await VK.confirm({ title: 'Send ' + ids.length + ' invoice(s)?', text: 'Each customer gets an email / SMS (by their preference) and a portal notification.' })) return;
  VK.btnLoading($(this), true); VK.api('billing/send-invoice', { ids }).then(r => { VK.toast(r.message); setTimeout(() => location.reload(), 500); }); });
</script>
<?php page_script(ob_get_clean());
