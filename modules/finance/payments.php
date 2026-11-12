<?php
$title = 'Payments';
$subtitle = 'Every shilling received — M-Pesa, Co-op Bank, Stripe, cash, cheques and other banks';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'method' => (string)($_GET['method'] ?? ''), 'account_id' => (int)($_GET['account_id'] ?? 0), 'from' => (string)($_GET['from'] ?? date('Y-m-01', strtotime('-2 months'))), 'to' => (string)($_GET['to'] ?? date('Y-m-d')), 'property_id' => (int)($_GET['property_id'] ?? 0), 'unmatched' => !empty($_GET['unmatched'])];
$w = ['DATE(pm.paid_at) BETWEEN :f AND :t', '(pm.property_id IS NULL OR ' . scope_sql('pm.property_id') . ')']; $p = ['f' => $f['from'], 't' => $f['to']];
if ($f['q'] !== '') { $w[] = '(pm.receipt_no LIKE :q OR pm.reference LIKE :q OR pm.payer_name LIKE :q OR pm.payer_phone LIKE :q OR pt.display_name LIKE :q OR u.unit_no LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['status'] === 'open') $w[] = "pm.status IN ('unallocated','partially_allocated')"; elseif ($f['status']) { $w[] = 'pm.status = :s'; $p['s'] = $f['status']; }
if ($f['method']) { $w[] = 'pm.method = :m'; $p['m'] = $f['method']; }
if ($f['account_id']) { $w[] = 'pm.account_id = :a'; $p['a'] = $f['account_id']; }
if ($f['property_id']) { $w[] = 'pm.property_id = :pid'; $p['pid'] = $f['property_id']; }
if ($f['unmatched']) $w[] = 'pm.party_id IS NULL AND pm.status <> \'reversed\'';
$sql = "FROM fin_payments pm LEFT JOIN prpt_parties pt ON pt.id = pm.party_id LEFT JOIN prpt_units u ON u.id = pm.unit_id LEFT JOIN fin_accounts fa ON fa.id = pm.account_id WHERE " . implode(' AND ', $w);
if (($_GET['export'] ?? '') === 'csv') csv_download('payments-' . date('Ymd') . '.csv', ['Receipt', 'Paid at', 'Method', 'Reference', 'Account', 'Customer', 'Unit', 'Amount', 'Allocated', 'Overpayment', 'Unallocated', 'Status'],
    all("SELECT pm.receipt_no, pm.paid_at, pm.method, pm.reference, fa.name, COALESCE(pt.display_name, pm.payer_name), u.unit_no, pm.amount, pm.allocated_amount, pm.credit_amount, pm.unallocated_amount, pm.status $sql ORDER BY pm.paid_at DESC", $p));
$k = one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN pm.status <> 'reversed' THEN pm.amount END),0) total, COALESCE(SUM(CASE WHEN pm.status <> 'reversed' THEN pm.unallocated_amount END),0) un, COALESCE(SUM(CASE WHEN pm.status <> 'reversed' THEN pm.credit_amount END),0) cr $sql", $p);
$unmatched = (int)val("SELECT COUNT(*) FROM fin_payments WHERE party_id IS NULL AND status <> 'reversed'");
[$lim, $off, $pager] = ui_paginate((int)$k['n'], 50);
$rows = all("SELECT pm.*, pt.display_name, u.unit_no, fa.name AS account $sql ORDER BY pm.paid_at DESC, pm.id DESC LIMIT $lim OFFSET $off", $p);
$accounts = all("SELECT id, name, account_type FROM fin_accounts WHERE is_active = 1 ORDER BY is_master DESC, name");
$methods = ['mpesa' => 'M-Pesa', 'bank_transfer' => 'Bank transfer', 'card' => 'Card (Stripe)', 'cash' => 'Cash', 'cheque' => 'Cheque', 'rtgs' => 'RTGS', 'eft' => 'EFT', 'other' => 'Other'];
$actions = '<a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export</a>' . (can('finance.payments.create') ? '<button class="vk-btn-accent" data-crud-new="#m-pay" data-title="Record payment"><i class="fa-solid fa-plus"></i>Record payment</button>' : '');
// prefill from ?new=1&party_id=&unit_id=&lease_id=&invoice_id=
$pre = ['party_id' => (int)($_GET['party_id'] ?? 0) ?: null, 'unit_id' => (int)($_GET['unit_id'] ?? 0) ?: null, 'invoice_id' => (int)($_GET['invoice_id'] ?? 0) ?: null, 'lease_id' => (int)($_GET['lease_id'] ?? 0) ?: null];
if ($pre['invoice_id'] && ($iv = one("SELECT party_id, unit_id, balance, invoice_no FROM bln_invoices WHERE id = ?", [$pre['invoice_id']]))) { $pre['party_id'] = (int)$iv['party_id']; $pre['unit_id'] = $iv['unit_id'] ? (int)$iv['unit_id'] : null; }
if ($pre['lease_id'] && !$pre['party_id'] && ($lz = one("SELECT unit_id FROM bln_leases WHERE id = ?", [$pre['lease_id']]))) { $pre['party_id'] = lease_primary_payer($pre['lease_id']); $pre['unit_id'] = (int)$lz['unit_id']; }
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
  <?= ui_stat('fa-money-bill-wave', 'Received in range', money($k['total'], true, true), 'green', num($k['n']) . ' payments') ?>
  <?= ui_stat('fa-hourglass-half', 'Not yet consumed', money($k['un']), 'accent', null, page('finance/payments', ['status' => 'open'])) ?>
  <?= ui_stat('fa-piggy-bank', 'Held as overpayment', money($k['cr']), 'violet') ?>
  <?= ui_stat('fa-circle-question', 'Unmatched payers', num($unmatched), $unmatched ? 'red' : 'slate', null, page('finance/payments', ['unmatched' => 1, 'from' => '2000-01-01'])) ?>
</div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Receipt, M-Pesa code, name, phone, unit"'), 'w-64') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['open' => 'Needs consuming', 'unallocated' => 'Unallocated', 'partially_allocated' => 'Partially', 'allocated' => 'Allocated', 'reversed' => 'Reversed'], $f['status'], 'Any')), 'w-40') ?>
  <?= ui_field('Method', ui_select('method', ui_kv_options($methods, $f['method'], 'Any')), 'w-36') ?>
  <?= ui_field('Account', ui_select('account_id', ui_options($accounts, 'id', 'name', $f['account_id'], 'Any')), 'w-48') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'Any')), 'w-44') ?>
  <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Receipt</th><th>Paid</th><th>Method / ref</th><th>Payer</th><th>Unit</th><th class="num">Amount</th><th class="num">Unconsumed</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr data-href="<?= e(page('finance/consume', ['payment' => $r['uuid']])) ?>">
      <td><b><?= e($r['receipt_no']) ?></b><span class="block text-[11px] text-muted"><?= e($r['account']) ?><?= $r['source'] === 'gateway' ? ' · <i class="fa-solid fa-tower-broadcast"></i> live' : '' ?></span></td>
      <td class="text-xs"><?= fdatetime($r['paid_at']) ?></td>
      <td class="text-sm"><?= e($methods[$r['method']] ?? $r['method']) ?><span class="block font-mono text-xs text-muted"><?= e($r['reference'] ?: '—') ?></span></td>
      <td class="text-sm"><?= $r['party_id'] ? e($r['display_name']) : '<span class="text-rose-600 font-semibold"><i class="fa-solid fa-circle-question"></i> ' . e($r['payer_name'] ?: 'Unknown') . '</span><span class="block text-xs text-muted">' . e($r['payer_phone']) . '</span>' ?></td>
      <td><?= e($r['unit_no'] ?? '—') ?></td>
      <td class="num font-bold"><?= money($r['amount'], false) ?></td>
      <td class="num"><?= (float)$r['unallocated_amount'] > 0 ? '<span class="text-accent font-bold">' . money($r['unallocated_amount'], false) . '</span>' : '<span class="text-muted">0.00</span>' ?><?= (float)$r['credit_amount'] > 0 ? '<span class="block text-[11px] text-violet-600">' . money($r['credit_amount'], false) . ' overpaid</span>' : '' ?></td>
      <td><?= pill($r['status']) ?></td>
      <td class="text-right whitespace-nowrap"><?php if (can('finance.allocate') && in_array($r['status'], ['unallocated', 'partially_allocated'], true)): ?><a class="vk-btn-accent !py-1.5 !px-3 text-xs" href="<?= e(page('finance/consume', ['payment' => $r['uuid']])) ?>">Consume</a><?php endif; ?>
        <?php if (can('finance.payments.create') && $r['status'] !== 'reversed'): ?><button class="vk-icon-btn !h-8 !w-8" data-crud-edit="#m-pay" data-row='<?= e(json_encode(['id' => $r['id'], 'account_id' => $r['account_id'], 'method' => $r['method'], 'reference' => $r['reference'], 'amount' => $r['amount'], 'paid_at' => $r['paid_at'], 'party_id' => $r['party_id'], 'unit_id' => $r['unit_id'], 'payer_name' => $r['payer_name'], 'payer_phone' => $r['payer_phone'], 'notes' => $r['notes']])) ?>' data-title="Edit <?= e($r['receipt_no']) ?>"><i class="fa-solid fa-pen text-xs"></i></button><?php endif; ?></td>
    </tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="9">' . ui_empty('fa-money-bill', 'No payments in this range') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
<?php if (can('finance.payments.create')):
$body = '<input type="hidden" name="id">' . ($pre['invoice_id'] ? '<input type="hidden" name="invoice_id" value="' . $pre['invoice_id'] . '">' : '') . ($pre['lease_id'] ? '<input type="hidden" name="lease_id" value="' . $pre['lease_id'] . '">' : '')
  . ($pre['invoice_id'] && !empty($iv) ? '<div class="rounded-xl bg-accent/10 p-3 text-sm"><i class="fa-solid fa-file-invoice text-accent mr-1"></i>For invoice <b>' . e($iv['invoice_no']) . '</b> · balance <b>' . money($iv['balance']) . '</b></div>' : '')
  . '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
  . ui_field('Amount (KSh) *', ui_input('amount', $pre['invoice_id'] && !empty($iv) ? $iv['balance'] : '', 'number', 'step="any" required'))
  . ui_field('Paid on *', ui_input('paid_at', date('Y-m-d\TH:i'), 'datetime-local', 'required'))
  . ui_field('Method *', ui_select('method', ui_kv_options($methods, 'mpesa'), 'required'))
  . ui_field('Received into *', ui_select('account_id', ui_options($accounts, 'id', 'name'), 'required'))
  . ui_field('Reference (M-Pesa code, cheque no, bank ref)', ui_input('reference', '', 'text', 'style="text-transform:uppercase"'))
  . ui_field('Paid by (person)', ui_party_picker('party_id', $pre['party_id']))
  . ui_field('For unit', ui_unit_picker('unit_id', $pre['unit_id']))
  . ui_field('Payer name (if not on file)', ui_input('payer_name'))
  . '<div class="sm:col-span-2">' . ui_field('Notes', ui_input('notes')) . '</div></div>'
  . '<div data-new-only><span class="vk-label">After saving</span><div class="flex flex-wrap gap-2">'
  . ($pre['invoice_id'] ? '<label class="vk-choice"><input type="radio" name="allocate" value="invoice" checked><span>Apply to this invoice</span></label>' : '')
  . '<label class="vk-choice"><input type="radio" name="allocate" value="consume" ' . ($pre['invoice_id'] ? '' : 'checked') . '><span><i class="fa-solid fa-arrows-split-up-and-left"></i>Consume manually</span></label>'
  . '<label class="vk-choice"><input type="radio" name="allocate" value="auto"><span><i class="fa-solid fa-wand-magic-sparkles"></i>Auto (oldest bills first)</span></label></div>'
  . '<label class="vk-switch mt-3"><input type="checkbox" name="send_receipt" value="1" checked>Send the receipt to the payer</label></div>';
echo ui_modal('m-pay', 'Record payment', $body, url('api.php', ['r' => 'finance/save-payment']), 'Save payment', 'max-w-2xl', 'fa-money-bill');
page_script('<script>$(document).on("click","[data-crud-edit=\'#m-pay\'],[data-crud-new=\'#m-pay\']",function(){setTimeout(()=>$("#m-pay [data-new-only]").toggle(!$("#m-pay [name=id]").val()),0)});' . (!empty($_GET['new']) ? '$(()=>{VK.openModal("#m-pay");$("#m-pay [data-new-only]").show()})' : '') . '</script>');
endif;
