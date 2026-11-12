<?php
$id = (int)($_GET['id'] ?? 0);
$inv = $id ? one("SELECT i.*, u.unit_no FROM bln_invoices i LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE i.id = ?", [$id]) : null;
if ($id && !$inv) { echo ui_empty('fa-file-invoice', 'Invoice not found'); return; }
if ($inv && in_array($inv['status'], ['void', 'written_off'], true)) { echo ui_empty('fa-ban', 'This invoice can no longer be edited'); return; }
if ($inv && !can('billing.invoices.edit')) { echo ui_empty('fa-lock', 'You cannot edit invoices'); return; }
$lease = null;
if (!$inv && ($lid = (int)($_GET['lease_id'] ?? 0))) $lease = one("SELECT * FROM bln_leases WHERE id = ?", [$lid]);
$unitId = $inv['unit_id'] ?? ($lease['unit_id'] ?? ((int)($_GET['unit_id'] ?? 0) ?: null));
$partyId = $inv['party_id'] ?? ((int)($_GET['party_id'] ?? 0) ?: ($lease ? lease_primary_payer((int)$lease['id']) : null));
$lines = $inv ? invoice_lines_with_balance($id) : [];
$title = $inv ? 'Edit invoice ' . $inv['invoice_no'] : 'New invoice';
$subtitle = $inv ? 'Every change is recorded in the invoice timeline and audit trail' : 'One-off charges, opening balances, repairs recharged to a tenant…';
$crumbs = [['Invoices', page('billing/invoices')]];
if ($inv) $crumbs[] = [$inv['invoice_no'], page('billing/invoice', ['id' => $id])];
$bts = all("SELECT id, name, default_amount, billing_method FROM cfg_bill_types WHERE is_active = 1 ORDER BY sort_order, name");
?>
<form data-ajax="<?= e(url('api.php', ['r' => 'billing/save-invoice'])) ?>" id="inv-form" class="space-y-5 max-w-5xl">
  <input type="hidden" name="id" value="<?= $id ?: '' ?>">
  <div class="vk-panel p-5 grid sm:grid-cols-4 gap-4">
    <?= ui_field('Bill to *', ui_party_picker('party_id', $partyId, '', 'required'), 'sm:col-span-2') ?>
    <?= $inv ? ui_field('Unit', '<input class="vk-input opacity-70" disabled value="' . e($inv['unit_no'] ?? '—') . '">', 'sm:col-span-2') : ui_field('Unit', ui_unit_picker('unit_id', $unitId), 'sm:col-span-2') ?>
    <?= ui_field('Issue date *', ui_input('issue_date', $inv['issue_date'] ?? date('Y-m-d'), 'date', 'required')) ?>
    <?= ui_field('Due date *', ui_input('due_date', $inv['due_date'] ?? date('Y-m-d', strtotime('+7 days')), 'date', 'required')) ?>
    <?= $inv ? '' : ui_field('Period (optional)', ui_input('period_month', '', 'month')) ?>
    <?= ui_field('Note on invoice', ui_input('notes', $inv['notes'] ?? ''), $inv ? 'sm:col-span-2' : '') ?>
  </div>
  <div class="vk-panel overflow-hidden">
    <table class="vk-table" id="lines"><thead><tr><th class="w-52">Bill type</th><th>Description</th><th class="w-24 num">Qty</th><th class="w-36 num">Rate</th><th class="w-36 num">Amount</th><th class="w-28 num">Paid</th><th class="w-10"></th></tr></thead><tbody></tbody>
      <tfoot><tr><td colspan="4"><button type="button" class="vk-btn-ghost !py-1.5 text-xs" id="add-line"><i class="fa-solid fa-plus"></i>Add line</button></td><td class="num font-extrabold text-lg" id="tot">0.00</td><td colspan="2"></td></tr></tfoot></table>
    <div data-field="lines" class="px-5 pb-3"></div>
  </div>
  <div class="flex flex-wrap items-center gap-3">
    <?php if (!$inv): ?><label class="vk-switch"><input type="checkbox" name="send" value="1" checked>Send to the customer now (email / SMS + portal)</label><label class="vk-switch"><input type="checkbox" name="draft" value="1">Save as draft</label><?php endif; ?>
    <button class="ml-auto vk-btn-accent"><i class="fa-solid fa-floppy-disk"></i><?= $inv ? 'Save changes' : 'Create invoice' ?></button>
  </div>
</form>
<?php ob_start(); ?>
<script>
(function () {
  const BTS = <?= json_encode($bts) ?>, LINES = <?= json_encode(array_map(fn($l) => ['id' => (int)$l['id'], 'bill_type_id' => (int)$l['bill_type_id'], 'description' => $l['description'], 'quantity' => (float)$l['quantity'], 'unit_price' => (float)$l['unit_price'], 'paid' => (float)$l['amount_paid']], $lines)) ?>;
  let n = 0; const $tb = $('#lines tbody');
  const opts = (sel) => '<option value="">Choose…</option>' + BTS.map(b => `<option value="${b.id}" ${+sel === +b.id ? 'selected' : ''} data-amt="${b.default_amount || ''}">${VK.esc(b.name)}</option>`).join('');
  function add(l) { l = l || {}; const i = n++; const locked = l.paid > 0;
    $tb.append(`<tr><td><input type="hidden" name="lines[${i}][id]" value="${l.id || ''}"><select name="lines[${i}][bill_type_id]" class="vk-select !py-1.5 bt">${opts(l.bill_type_id)}</select></td>
      <td><input name="lines[${i}][description]" value="${VK.esc(l.description || '')}" class="vk-input !py-1.5" placeholder="Description"></td>
      <td><input name="lines[${i}][quantity]" value="${l.quantity ?? 1}" type="number" step="any" class="vk-input !py-1.5 text-right q"></td>
      <td><input name="lines[${i}][unit_price]" value="${l.unit_price ?? ''}" type="number" step="any" class="vk-input !py-1.5 text-right p"></td>
      <td class="num font-bold amt">0.00</td><td class="num text-xs ${locked ? 'text-emerald-600 font-semibold' : 'text-muted'}">${(l.paid || 0).toFixed(2)}</td>
      <td>${locked ? '<i class="fa-solid fa-lock text-muted text-xs" title="Has payments — cannot be removed"></i>' : '<button type="button" class="vk-icon-btn !h-8 !w-8 rm"><i class="fa-solid fa-xmark text-xs"></i></button>'}</td></tr>`); calc(); }
  function calc() { let t = 0; $tb.find('tr').each(function () { const a = VK.num($(this).find('.q').val()) * VK.num($(this).find('.p').val()); t += a; $(this).find('.amt').text(a.toLocaleString('en-KE', { minimumFractionDigits: 2 })); }); $('#tot').text(VK.money(t)); }
  $tb.on('input', '.q,.p', calc).on('click', '.rm', function () { $(this).closest('tr').remove(); calc(); })
     .on('change', '.bt', function () { const $tr = $(this).closest('tr'), o = $(this).find(':selected'); if (!$tr.find('.p').val() && o.data('amt')) $tr.find('.p').val(o.data('amt')); if (!$tr.find('[name$="[description]"]').val()) $tr.find('[name$="[description]"]').val(o.text()); calc(); });
  $('#add-line').on('click', () => add());
  LINES.length ? LINES.forEach(add) : add();
})();
</script>
<?php page_script(ob_get_clean());
