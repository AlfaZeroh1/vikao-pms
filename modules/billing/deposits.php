<?php
$title = 'Deposits';
$subtitle = 'Security deposits held for tenants — who holds them, refunds and deductions';
$st = (string)($_GET['status'] ?? '');
$w = [scope_sql('l.property_id')]; $p = [];
if ($st) { $w[] = 'd.status = :s'; $p['s'] = $st; }
if ($lid = (int)($_GET['lease_id'] ?? 0)) { $w[] = 'd.lease_id = :l'; $p['l'] = $lid; }
$rows = all("SELECT d.*, l.lease_no, l.status AS lease_status, l.end_date, u.unit_no, pr.name AS property, pt.display_name, bt.name AS bt, i.status AS inv_status, i.amount_paid AS inv_paid
    FROM bln_deposits d JOIN bln_leases l ON l.id = d.lease_id JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id JOIN prpt_parties pt ON pt.id = d.party_id JOIN cfg_bill_types bt ON bt.id = d.bill_type_id LEFT JOIN bln_invoices i ON i.id = d.invoice_id
    WHERE " . implode(' AND ', $w) . " ORDER BY d.status = 'pending' DESC, d.created_at DESC LIMIT 300", $p);
$k = one("SELECT COALESCE(SUM(CASE WHEN status IN ('held','partially_refunded') THEN amount_held - refunded_amount - deductions END),0) held, COALESCE(SUM(CASE WHEN status = 'pending' THEN amount_required END),0) pending, COALESCE(SUM(refunded_amount),0) refunded FROM bln_deposits");
?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
  <?= ui_stat('fa-vault', 'Currently held', money($k['held']), 'violet') ?><?= ui_stat('fa-hourglass-half', 'Awaiting payment', money($k['pending']), 'accent') ?><?= ui_stat('fa-rotate-left', 'Refunded to date', money($k['refunded']), 'green') ?>
</div>
<div class="flex flex-wrap gap-2 mb-4"><?php foreach (['' => 'All', 'pending' => 'Pending', 'held' => 'Held', 'partially_refunded' => 'Partially refunded', 'refunded' => 'Refunded', 'applied' => 'Applied to arrears', 'forfeited' => 'Forfeited'] as $kk => $l): ?><a class="vk-chip <?= $st === $kk ? '!border-accent !bg-accent/10' : '' ?>" href="<?= e(page('billing/deposits', ['status' => $kk])) ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Tenant</th><th>Account</th><th>Type</th><th class="num">Required</th><th class="num">Held</th><th>Held by</th><th class="num">Refunded</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $d): $paidIn = $d['inv_paid'] !== null ? (float)$d['inv_paid'] : 0; ?>
    <tr><td><b><?= e($d['display_name']) ?></b></td><td><a class="hover:text-accent" href="<?= e(page('billing/lease', ['id' => $d['lease_id']])) ?>"><?= e($d['lease_no']) ?></a><span class="block text-xs text-muted"><?= e($d['unit_no']) ?> · <?= e($d['property']) ?> · <?= e($d['lease_status']) ?></span></td>
      <td class="text-sm"><?= e($d['bt']) ?></td><td class="num"><?= money($d['amount_required'], false) ?><?= $d['status'] === 'pending' ? '<span class="block text-[11px] ' . ($paidIn >= (float)$d['amount_required'] ? 'text-emerald-600' : 'text-muted') . '">paid ' . money($paidIn, false) . '</span>' : '' ?></td>
      <td class="num font-bold"><?= money($d['amount_held'], false) ?></td><td><?= e($d['held_by']) ?></td><td class="num"><?= money($d['refunded_amount'], false) ?><?= $d['deductions'] > 0 ? '<span class="block text-[11px] text-rose-600">−' . money($d['deductions'], false) . ' deducted</span>' : '' ?></td><td><?= pill($d['status']) ?></td>
      <td class="text-right whitespace-nowrap"><?php if (can('billing.deposits.manage')): ?>
        <?php if ($d['status'] === 'pending'): ?><button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'billing/deposit-update'])) ?>" data-payload='{"id":<?= $d['id'] ?>,"do":"held","amount_held":<?= $paidIn ?: (float)$d['amount_required'] ?>}' data-confirm="Mark <?= e(money($paidIn ?: $d['amount_required'])) ?> as received and held?" data-icon="question">Mark held</button><?php endif; ?>
        <?php if (in_array($d['status'], ['held'], true)): ?>
          <button class="vk-btn-ghost !py-1.5 text-xs" data-crud-edit="#m-refund" data-row='<?= e(json_encode(['id' => $d['id'], 'deductions' => 0, 'notes' => ''])) ?>' data-title="Refund deposit — <?= e($d['display_name']) ?> (held <?= e(money($d['amount_held'])) ?>)">Refund</button>
          <button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'billing/deposit-update'])) ?>" data-payload='{"id":<?= $d['id'] ?>,"do":"apply"}' data-confirm="Use the deposit to clear this tenant's open bills on the account?">Apply to arrears</button>
          <button class="vk-btn-ghost !py-1.5 text-xs !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'billing/deposit-update'])) ?>" data-payload='{"id":<?= $d['id'] ?>,"do":"forfeit"}' data-prompt="Reason for forfeiting" data-prompt-name="notes">Forfeit</button>
        <?php endif; endif; ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="9">' . ui_empty('fa-vault', 'No deposits') . '</td></tr>'; ?>
  </tbody></table></div></div>
<?= ui_modal('m-refund', 'Refund deposit', '<input type="hidden" name="id"><input type="hidden" name="do" value="refund"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Deductions (damages, unpaid bills)', ui_input('deductions', 0, 'number', 'step="any"')) . ui_field('Refund date', ui_input('refund_date', date('Y-m-d'), 'date'))
  . '<div class="sm:col-span-2">' . ui_field('Notes / deduction breakdown', '<textarea name="notes" class="vk-textarea" rows="2"></textarea>') . '</div></div><label class="vk-switch"><input type="checkbox" name="record_expense" value="1" checked>Record the refund as a payout (expense)</label>', url('api.php', ['r' => 'billing/deposit-update']), 'Refund', 'max-w-lg', 'fa-rotate-left') ?>
