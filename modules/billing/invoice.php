<?php
$id = (int)($_GET['id'] ?? 0);
$i = one("SELECT i.*, pt.display_name, pt.email, pt.phone, pt.party_no, pt.postal_address, u.unit_no, pr.name AS property, l.lease_no FROM bln_invoices i JOIN prpt_parties pt ON pt.id = i.party_id JOIN prpt_properties pr ON pr.id = i.property_id LEFT JOIN prpt_units u ON u.id = i.unit_id LEFT JOIN bln_leases l ON l.id = i.lease_id WHERE i.id = ?", [$id]);
if (!$i) { echo ui_empty('fa-file-invoice', 'Invoice not found'); return; }
$lines = invoice_lines_with_balance($id);
$events = all("SELECT * FROM bln_invoice_events WHERE invoice_id = ? ORDER BY created_at DESC, id DESC", [$id]);
$credits = all("SELECT * FROM bln_credit_notes WHERE invoice_id = ?", [$id]);
$print = !empty($_GET['print']);
if ($print) {
    $layout = 'print'; $title = 'Invoice ' . $i['invoice_no']; ?>
  <div class="grid2"><div><h1>INVOICE</h1><p class="muted"><?= e($i['invoice_no']) ?><?= $i['period_month'] ? ' · ' . month_label($i['period_month']) : '' ?></p>
    <p><b><?= e($i['display_name']) ?></b><br><?= e($i['party_no']) ?><br><?= e($i['phone']) ?> <?= e($i['email']) ?><br><?= e($i['postal_address']) ?></p></div>
    <div class="right"><p>Issued <b><?= fdate($i['issue_date']) ?></b><br>Due <b><?= fdate($i['due_date']) ?></b></p><p>Unit <b><?= e($i['unit_no'] ?? '—') ?></b><br><?= e($i['property']) ?><br><?= $i['lease_no'] ? 'Account ' . e($i['lease_no']) : '' ?></p>
    <?= $i['status'] === 'paid' ? '<span class="stamp">PAID</span>' : ($i['status'] === 'void' ? '<span class="stamp red">VOID</span>' : '') ?></div></div>
  <table><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Tax</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($lines as $l): ?><tr><td><?= e($l['description']) ?></td><td class="num"><?= num($l['quantity'], 2) ?></td><td class="num"><?= money($l['unit_price'], false) ?></td><td class="num"><?= money($l['tax_amount'], false) ?></td><td class="num"><?= money($l['amount'] + $l['tax_amount'], false) ?></td></tr><?php endforeach; ?>
  <tr class="tot"><td colspan="4">Total</td><td class="num"><?= money($i['total']) ?></td></tr>
  <tr><td colspan="4">Paid</td><td class="num"><?= money($i['amount_paid']) ?></td></tr>
  <?php foreach ($credits as $c): ?><tr><td colspan="4">Credit note <?= e($c['credit_no']) ?> — <?= e($c['reason']) ?></td><td class="num">−<?= money($c['amount']) ?></td></tr><?php endforeach; ?>
  <tr class="tot"><td colspan="4">Balance due</td><td class="num"><?= money($i['balance']) ?></td></tr></tbody></table>
  <div class="box"><b>How to pay</b><br>M-Pesa Paybill <b><?= e(setting('paybill_number', '—')) ?></b>, account <b><?= e($i['unit_no'] ?: $i['invoice_no']) ?></b> · or pay online in your self-service portal.<?= $i['notes'] ? '<br><br>' . e($i['notes']) : '' ?></div>
<?php return; }

$title = 'Invoice ' . $i['invoice_no'];
$subtitle = e($i['display_name']) . ' · ' . e(($i['unit_no'] ? $i['unit_no'] . ' · ' : '') . $i['property']) . ' · ' . pill($i['status']);
$crumbs = [['Invoices', page('billing/invoices')]];
$open = !in_array($i['status'], ['void', 'written_off'], true);
$a = ['<a class="vk-btn-ghost" target="_blank" href="' . e(page('billing/invoice', ['id' => $id, 'print' => 1])) . '"><i class="fa-solid fa-print"></i>Print / PDF</a>'];
if ($i['status'] === 'draft' && can('billing.invoices.create')) $a[] = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'billing/issue-invoice', 'id' => $id])) . '" data-confirm="Issue this draft?" data-icon="question"><i class="fa-solid fa-check"></i>Issue</button>';
if ($open && $i['status'] !== 'draft' && can('billing.invoices.send')) $a[] = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'billing/send-invoice', 'id' => $id])) . '" data-confirm="Send to ' . e($i['email'] ?: $i['phone'] ?: 'portal') . '?" data-icon="question"><i class="fa-solid fa-paper-plane"></i>' . ($i['sent_at'] ? 'Resend' : 'Send') . '</button>';
if ($open && can('billing.invoices.edit')) $a[] = '<a class="vk-btn-ghost" href="' . e(page('billing/invoice-edit', ['id' => $id])) . '"><i class="fa-solid fa-pen"></i>Edit</a>';
if ($open && (float)$i['balance'] > 0 && can('finance.payments.create')) $a[] = '<a class="vk-btn-accent" href="' . e(page('finance/payments', ['new' => 1, 'invoice_id' => $id])) . '"><i class="fa-solid fa-money-bill"></i>Receive payment</a>';
$actions = implode('', $a);
$evIcon = ['created' => ['fa-file-circle-plus', 'blue'], 'edited' => ['fa-pen', 'amber'], 'line_added' => ['fa-plus', 'amber'], 'line_removed' => ['fa-minus', 'amber'], 'sent' => ['fa-paper-plane', 'violet'], 'viewed' => ['fa-eye', 'slate'], 'payment' => ['fa-money-bill', 'green'],
    'payment_reversed' => ['fa-rotate-left', 'red'], 'credit_applied' => ['fa-piggy-bank', 'green'], 'penalty' => ['fa-gavel', 'red'], 'voided' => ['fa-ban', 'red'], 'written_off' => ['fa-eraser', 'red'], 'reminder' => ['fa-bell', 'violet'], 'note' => ['fa-note-sticky', 'slate']];
?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_380px] gap-6 items-start">
  <div class="space-y-6">
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
      <?= ui_stat('fa-file-invoice', 'Total', money($i['total']), 'blue') ?>
      <a href="#" data-paid='{"invoice_id":<?= $id ?>}' data-paid-title="Payments on <?= e($i['invoice_no']) ?>" class="block"><?= ui_stat('fa-circle-check', 'Paid — click for history', money($i['amount_paid']), 'green') ?></a>
      <?= ui_stat('fa-scale-balanced', 'Balance', money($i['balance']), $i['balance'] > 0 ? 'red' : 'green') ?>
      <?= ui_stat('fa-calendar', 'Due', fdate($i['due_date']), $i['balance'] > 0 && $i['due_date'] < date('Y-m-d') ? 'red' : 'slate', $i['balance'] > 0 && $i['due_date'] < date('Y-m-d') ? (int)((time() - strtotime($i['due_date'])) / 86400) . ' days late' : null) ?>
    </div>
    <div class="vk-panel overflow-hidden">
      <div class="flex flex-wrap items-center gap-4 px-5 py-4 border-b border-line text-sm">
        <div><span class="text-muted">Billed to</span><a class="block font-bold hover:text-accent" href="<?= e(page('property/party', ['id' => $i['party_id']])) ?>"><?= e($i['display_name']) ?></a></div>
        <div><span class="text-muted">Account</span><?= $i['lease_id'] ? '<a class="block font-bold hover:text-accent" href="' . e(page('billing/lease', ['id' => $i['lease_id']])) . '">' . e($i['lease_no']) . '</a>' : '<b class="block">—</b>' ?></div>
        <div><span class="text-muted">Issued</span><b class="block"><?= fdate($i['issue_date']) ?></b></div>
        <div><span class="text-muted">Period</span><b class="block"><?= $i['period_month'] ? month_label($i['period_month']) : '—' ?></b></div>
        <div><span class="text-muted">Source</span><b class="block"><?= e(str_replace('_', ' ', $i['source'])) ?></b></div>
        <div><span class="text-muted">Sent</span><b class="block"><?= $i['sent_at'] ? fdatetime($i['sent_at']) : 'Not yet' ?></b></div>
      </div>
      <div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>Bill</th><th>Description</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>
      <?php foreach ($lines as $l): ?><tr><td><span class="inline-block h-2.5 w-2.5 rounded-full mr-1.5" style="background:<?= e($l['color_hex']) ?>"></span><?= e($l['bill_type']) ?></td><td class="text-sm"><?= e($l['description']) ?></td>
        <td class="num"><?= num($l['quantity'], 2) ?></td><td class="num"><?= money($l['unit_price'], false) ?></td><td class="num"><?= money($l['amount'] + $l['tax_amount'], false) ?><?= $l['tax_amount'] > 0 ? '<span class="block text-[11px] text-muted">incl. tax ' . money($l['tax_amount'], false) . '</span>' : '' ?></td>
        <td class="num"><?= (float)$l['amount_paid'] > 0 ? '<a href="#" class="text-emerald-600 font-semibold underline decoration-dotted" data-paid=\'{"line_id":' . $l['id'] . '}\' data-paid-title="' . e($l['bill_type']) . ' payments">' . money($l['amount_paid'], false) . '</a>' : '0.00' ?></td>
        <td class="num"><?= ui_money_cell($l['balance'], true) ?></td></tr><?php endforeach; ?>
      </tbody><tfoot><tr class="font-bold"><td colspan="4">Total</td><td class="num"><?= money($i['total'], false) ?></td><td class="num"><?= money($i['amount_paid'], false) ?></td><td class="num"><?= money($i['balance'], false) ?></td></tr></tfoot></table></div>
      <?php if ($credits): ?><div class="px-5 py-3 border-t border-line text-sm"><?php foreach ($credits as $c): ?><p><i class="fa-solid fa-receipt text-violet-500 mr-1"></i>Credit note <b><?= e($c['credit_no']) ?></b> −<?= money($c['amount']) ?> · <?= e($c['reason']) ?> <span class="text-muted">(<?= fdate($c['created_at']) ?>)</span></p><?php endforeach; ?></div><?php endif; ?>
      <?php if ($i['notes'] || $i['void_reason']): ?><div class="px-5 py-3 border-t border-line text-sm text-muted"><?= e($i['notes']) ?><?= $i['void_reason'] ? '<p class="text-rose-600">' . e($i['void_reason']) . '</p>' : '' ?></div><?php endif; ?>
    </div>
    <?php if ($open && (can('billing.invoices.void') || can('billing.invoices.edit'))): ?>
    <div class="flex flex-wrap gap-2">
      <?php if (can('billing.invoices.edit') && $i['balance'] > 0): ?><button class="vk-btn-ghost text-sm" data-modal-open="#m-credit"><i class="fa-solid fa-receipt"></i>Credit note</button><?php endif; ?>
      <?php if (can('billing.invoices.void')): ?>
        <?php if ($i['balance'] > 0 && $i['amount_paid'] > 0): ?><button class="vk-btn-ghost text-sm" data-action="<?= e(url('api.php', ['r' => 'billing/write-off', 'id' => $id])) ?>" data-prompt="Reason for writing off <?= e(money($i['balance'])) ?>"><i class="fa-solid fa-eraser"></i>Write off balance</button><?php endif; ?>
        <?php if ((float)$i['amount_paid'] == 0.0): ?><button class="vk-btn-ghost text-sm !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'billing/void-invoice', 'id' => $id])) ?>" data-prompt="Why are you voiding this invoice?"><i class="fa-solid fa-ban"></i>Void</button>
          <button class="vk-btn-ghost text-sm !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'billing/delete-invoice', 'id' => $id])) ?>" data-confirm="Permanently delete <?= e($i['invoice_no']) ?>? Readings and bills it covered become billable again."><i class="fa-regular fa-trash-can"></i>Delete</button><?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="vk-panel p-5 xl:sticky xl:top-20">
    <div class="flex items-center mb-4"><h3 class="font-bold"><i class="fa-solid fa-timeline text-accent mr-2"></i>Invoice timeline</h3>
      <button class="ml-auto vk-btn-ghost !py-1 !px-2 text-xs" data-action="<?= e(url('api.php', ['r' => 'billing/invoice-note', 'id' => $id])) ?>" data-prompt="Add a note to the timeline" data-prompt-name="note"><i class="fa-solid fa-plus"></i>Note</button></div>
    <?= ui_timeline(array_map(function ($ev) use ($evIcon) {
        [$ic, $tone] = $evIcon[$ev['event']] ?? ['fa-circle', 'slate'];
        $body = ($ev['amount'] !== null ? '<b>' . money($ev['amount']) . '</b>' : '') . ($ev['balance_after'] !== null ? ' · balance ' . money($ev['balance_after']) : '');
        if ($ev['changes']) { $ch = json_decode($ev['changes'], true) ?: []; $body .= '<ul class="mt-1 text-xs space-y-0.5">'; foreach ($ch as $k => $c) $body .= '<li><span class="text-muted">' . e(str_replace('_', ' ', preg_replace('/_\d+$/', '', $k))) . ':</span> ' . ($c['from'] !== null ? '<s>' . e((string)$c['from']) . '</s> ' : '') . ($c['to'] !== null ? '→ ' . e((string)$c['to']) : '') . '</li>'; $body .= '</ul>'; }
        return [$ic, $tone, e($ev['description']), $body, fdatetime($ev['created_at']), $ev['actor_name'] ?: ucfirst($ev['actor_type'])];
    }, $events)) ?>
  </div>
</div>
<?= ui_modal('m-credit', 'Credit note', '<input type="hidden" name="id" value="' . $id . '">' . ui_field('Amount (max ' . money($i['balance']) . ')', ui_input('amount', '', 'number', 'step="any" max="' . $i['balance'] . '" required')) . ui_field('Reason *', ui_input('reason', '', 'text', 'required placeholder="e.g. Water billing error"')), url('api.php', ['r' => 'billing/credit-note']), 'Apply credit', 'max-w-md', 'fa-receipt') ?>
