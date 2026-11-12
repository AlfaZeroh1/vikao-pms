<?php
$title = 'Expenses';
$subtitle = 'Costs tied to a property, house, tenant, owner or CRM ticket — owner-chargeable ones are deducted in remittances';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'property_id' => (int)($_GET['property_id'] ?? 0), 'category_id' => (int)($_GET['category_id'] ?? 0), 'charge' => (string)($_GET['charge'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'from' => (string)($_GET['from'] ?? date('Y-m-01', strtotime('-2 months'))), 'to' => (string)($_GET['to'] ?? date('Y-m-d')), 'ticket_id' => (int)($_GET['ticket_id'] ?? 0)];
$w = ['e.expense_date BETWEEN :f AND :t', '(e.property_id IS NULL OR ' . scope_sql('e.property_id') . ')']; $p = ['f' => $f['from'], 't' => $f['to']];
if ($f['q'] !== '') { $w[] = '(e.description LIKE :q OR e.expense_no LIKE :q OR v.name LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
foreach (['property_id' => 'e.property_id', 'category_id' => 'e.category_id', 'ticket_id' => 'e.ticket_id'] as $k => $c) if ($f[$k]) { $w[] = "$c = :$k"; $p[$k] = $f[$k]; }
if ($f['charge']) { $w[] = 'e.chargeable_to = :ch'; $p['ch'] = $f['charge']; }
if ($f['status']) { $w[] = 'e.status = :st'; $p['st'] = $f['status']; }
$sql = "FROM fin_expenses e JOIN fin_expense_categories c ON c.id = e.category_id LEFT JOIN fin_vendors v ON v.id = e.vendor_id LEFT JOIN prpt_properties pr ON pr.id = e.property_id LEFT JOIN prpt_units u ON u.id = e.unit_id LEFT JOIN prpt_parties pt ON pt.id = e.party_id LEFT JOIN crm_tickets tk ON tk.id = e.ticket_id WHERE " . implode(' AND ', $w);
if (($_GET['export'] ?? '') === 'csv') csv_download('expenses-' . date('Ymd') . '.csv', ['No', 'Date', 'Category', 'Vendor', 'Description', 'Property', 'Unit', 'Charged to', 'Amount', 'Tax', 'Status', 'Ticket'], all("SELECT e.expense_no, e.expense_date, c.name, v.name, e.description, pr.name, u.unit_no, e.chargeable_to, e.amount, e.tax_amount, e.status, tk.ticket_no $sql ORDER BY e.expense_date DESC", $p));
$k = one("SELECT COUNT(*) n, COALESCE(SUM(e.amount + e.tax_amount),0) tot, COALESCE(SUM(CASE WHEN e.chargeable_to = 'owner' THEN e.amount END),0) owner, COALESCE(SUM(CASE WHEN e.status = 'draft' THEN 1 END),0) pending $sql AND e.status <> 'rejected'", $p);
$byCat = all("SELECT c.name, SUM(e.amount) amt $sql AND e.status <> 'rejected' GROUP BY c.id ORDER BY amt DESC LIMIT 6", $p);
[$lim, $off, $pager] = ui_paginate((int)val("SELECT COUNT(*) $sql", $p), 50);
$rows = all("SELECT e.*, c.name AS category, v.name AS vendor, pr.name AS property, u.unit_no, pt.display_name AS party, tk.ticket_no $sql ORDER BY e.expense_date DESC, e.id DESC LIMIT $lim OFFSET $off", $p);
$cats = all("SELECT id, name, default_chargeable_to FROM fin_expense_categories WHERE is_active = 1 ORDER BY name");
$actions = '<a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export</a><button class="vk-btn-accent" data-crud-new="#m-exp" data-title="New expense"><i class="fa-solid fa-plus"></i>Add expense</button>';
?>
<div class="grid grid-cols-1 lg:grid-cols-[1fr_1fr] gap-4 mb-5">
  <div class="grid grid-cols-2 gap-3"><?= ui_stat('fa-receipt', 'Expenses in range', money($k['tot'], true, true), 'red', num($k['n']) . ' items') ?><?= ui_stat('fa-user-tie', 'Chargeable to owners', money($k['owner'], true, true), 'violet') ?>
    <?= ui_stat('fa-hourglass-half', 'Awaiting approval', num($k['pending']), 'accent', null, page('finance/expenses', ['status' => 'draft'])) ?><?= ui_stat('fa-tags', 'Categories', num(count($cats)), 'slate', null, can('finance.expenses.manage') ? page('config/lists', ['tab' => 'expense']) : null) ?></div>
  <div class="vk-panel p-5"><p class="text-sm font-bold mb-2">Top categories</p><?php $mx = max(1, ...array_map(fn($r) => (float)$r['amt'], $byCat ?: [['amt' => 1]])); foreach ($byCat as $r): ?><div class="flex items-center gap-3 text-sm mb-1.5"><span class="w-36 truncate"><?= e($r['name']) ?></span><div class="flex-1 h-2 rounded-full bg-surface2 overflow-hidden"><div class="h-full bg-rose-500/70" style="width:<?= $r['amt'] / $mx * 100 ?>%"></div></div><b class="w-28 text-right tabular-nums"><?= money($r['amt'], false) ?></b></div><?php endforeach; if (!$byCat) echo '<p class="text-sm text-muted">No expenses yet.</p>'; ?></div>
</div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Description, vendor, no."'), 'w-56') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All')), 'w-44') ?>
  <?= ui_field('Category', ui_select('category_id', ui_options($cats, 'id', 'name', $f['category_id'], 'All')), 'w-44') ?>
  <?= ui_field('Charged to', ui_select('charge', ui_kv_options(['owner' => 'Owner', 'agency' => 'Agency', 'property_fund' => 'Property fund', 'tenant' => 'Tenant'], $f['charge'], 'Anyone')), 'w-36') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid', 'rejected' => 'Rejected'], $f['status'], 'Any')), 'w-32') ?>
  <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Expense</th><th>Category / vendor</th><th>Tied to</th><th>Charged to</th><th class="num">Amount</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $x): ?><tr>
    <td><b><?= e($x['description']) ?></b><span class="block text-xs text-muted"><?= e($x['expense_no']) ?> · <?= fdate($x['expense_date']) ?><?= $x['receipt_path'] ? ' · <a class="text-accent" target="_blank" href="' . e(file_url($x['receipt_path'])) . '"><i class="fa-solid fa-paperclip"></i> receipt</a>' : '' ?></span></td>
    <td class="text-sm"><?= e($x['category']) ?><span class="block text-xs text-muted"><?= e($x['vendor'] ?: '') ?></span></td>
    <td class="text-xs"><?= e($x['property'] ?: '—') ?><?= $x['unit_no'] ? ' · ' . e($x['unit_no']) : '' ?><?= $x['party'] ? '<span class="block">' . e($x['party']) . '</span>' : '' ?><?= $x['ticket_no'] ? '<a class="block text-accent" href="' . e(page('crm/ticket', ['id' => $x['ticket_id']])) . '"><i class="fa-solid fa-ticket"></i> ' . e($x['ticket_no']) . '</a>' : '' ?></td>
    <td class="text-sm"><?= e(str_replace('_', ' ', $x['chargeable_to'])) ?><?= $x['remittance_id'] ? '<span class="block text-[11px] text-violet-600">in remittance</span>' : '' ?></td>
    <td class="num font-bold"><?= money($x['amount'] + $x['tax_amount'], false) ?></td><td><?= pill($x['status']) ?></td>
    <td class="text-right whitespace-nowrap">
      <?php if ($x['status'] === 'draft'): ?><button class="vk-icon-btn !h-8 !w-8" title="Approve" data-action="<?= e(url('api.php', ['r' => 'finance/expense-status'])) ?>" data-payload='{"id":<?= $x['id'] ?>,"status":"approved"}'><i class="fa-solid fa-check text-emerald-600 text-xs"></i></button><?php endif; ?>
      <?php if (in_array($x['status'], ['draft', 'approved'], true)): ?><button class="vk-icon-btn !h-8 !w-8" title="Mark paid" data-action="<?= e(url('api.php', ['r' => 'finance/expense-status'])) ?>" data-payload='{"id":<?= $x['id'] ?>,"status":"paid"}' data-prompt="Payment reference (optional)" data-prompt-name="payment_ref" data-prompt-optional="1"><i class="fa-solid fa-money-check text-xs"></i></button><?php endif; ?>
      <button class="vk-icon-btn !h-8 !w-8" data-crud-edit="#m-exp" data-row='<?= e(json_encode($x)) ?>' data-title="Edit <?= e($x['expense_no']) ?>"><i class="fa-solid fa-pen text-xs"></i></button>
      <?php if (!$x['remittance_id']): ?><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'finance/delete-expense', 'id' => $x['id']])) ?>" data-confirm="Delete this expense?"><i class="fa-regular fa-trash-can text-xs"></i></button><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="7">' . ui_empty('fa-receipt', 'No expenses in this range') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
<?php
$body = '<input type="hidden" name="id"><div class="grid grid-cols-1 sm:grid-cols-3 gap-4">'
  . ui_field('Description *', ui_input('description', '', 'text', 'required'), 'sm:col-span-2') . ui_field('Date *', ui_input('expense_date', date('Y-m-d'), 'date', 'required'))
  . ui_field('Category *', ui_select('category_id', ui_options($cats, 'id', 'name', null, 'Choose…'), 'required id="ex-cat"')) . ui_field('Amount *', ui_input('amount', '', 'number', 'step="any" required')) . ui_field('Tax', ui_input('tax_amount', 0, 'number', 'step="any"'))
  . ui_field('Vendor', ui_select('vendor_id', ui_options(all("SELECT id, name FROM fin_vendors WHERE is_active = 1 ORDER BY name"), 'id', 'name', null, '—')))
  . ui_field('Charged to *', ui_select('chargeable_to', ui_kv_options(['owner' => 'Owner (deducted in remittance)', 'agency' => 'Agency', 'property_fund' => 'Property / service-charge fund', 'tenant' => 'Tenant (recharge)'], 'owner'), 'required id="ex-ch"'))
  . ui_field('Status', ui_select('status', ui_kv_options(['approved' => 'Approved', 'draft' => 'Draft (needs approval)', 'paid' => 'Paid'])))
  . ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', null, '—'))) . ui_field('House / unit', ui_unit_picker('unit_id')) . ui_field('Tenant / owner', ui_party_picker('party_id'))
  . ui_field('CRM ticket', ui_select('ticket_id', ui_options(all("SELECT id, CONCAT(ticket_no, ' — ', subject) AS name FROM crm_tickets WHERE status NOT IN ('closed','cancelled') OR created_at > DATE_SUB(NOW(), INTERVAL 60 DAY) ORDER BY id DESC LIMIT 200"), 'id', 'name', (int)($_GET['ticket_id'] ?? 0) ?: null, '—')))
  . ui_field('Paid from', ui_select('paid_from_account_id', ui_options(all("SELECT id, name FROM fin_accounts WHERE is_active = 1"), 'id', 'name', null, '—'))) . ui_field('Payment ref', ui_input('payment_ref'))
  . '<div class="sm:col-span-3">' . ui_field('Receipt / invoice (photo or PDF)', '<input type="file" name="receipt" class="vk-input" accept="image/*,application/pdf">') . '</div></div>'
  . '<label class="vk-switch hidden" id="ex-recharge"><input type="checkbox" name="recharge" value="1" checked>Raise an invoice to the tenant for this amount</label>';
echo ui_modal('m-exp', 'Expense', $body, url('api.php', ['r' => 'finance/save-expense']), 'Save expense', 'max-w-3xl', 'fa-receipt');
page_script('<script>const EXC=' . json_encode(array_column($cats, 'default_chargeable_to', 'id')) . ';$("#ex-cat").on("change",function(){if(EXC[this.value])$("#ex-ch").val(EXC[this.value]).trigger("change")});$("#ex-ch").on("change",function(){$("#ex-recharge").toggleClass("hidden",this.value!=="tenant"||!!$("#m-exp [name=id]").val())});' . (!empty($_GET['new']) || !empty($_GET['ticket_id']) ? '$(()=>VK.openModal("#m-exp"))' : '') . '</script>');
