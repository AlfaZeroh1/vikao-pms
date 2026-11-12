<?php
$title = 'M-Pesa · Co-op Bank · Stripe';
$subtitle = 'Live collection feeds into the Vikao master accounts — every transaction is matched to a tenant and consumed automatically';
$tab = $_GET['tab'] ?? 'mpesa';
$P = ['mpesa' => ['M-Pesa paybill', 'fa-mobile-screen', 'mpesa_vikao', 'emerald'], 'coop' => ['Co-op Bank', 'fa-building-columns', 'coop_vikao', 'sky'], 'stripe' => ['Stripe (cards)', 'fa-credit-card', 'stripe_vikao', 'violet']];
$stats = []; foreach (all("SELECT provider, COUNT(*) n, SUM(amount) amt, SUM(match_status = 'unmatched') un FROM fin_gateway_transactions WHERE DATE(transacted_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY provider") as $s) $stats[$s['provider']] = $s;
echo '<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">';
foreach ($P as $k => [$l, $ic, $code, $tone]) { $i = integration($code); $s = $stats[$k] ?? ['n' => 0, 'amt' => 0, 'un' => 0];
    echo '<a href="' . e(page('finance/gateways', ['tab' => $k])) . '" class="vk-panel p-4 flex items-center gap-3 ' . ($tab === $k ? 'ring-2 ring-accent' : 'hover:border-accent/50') . '"><span class="grid h-11 w-11 place-items-center rounded-xl bg-' . $tone . '-500/10 text-' . $tone . '-600"><i class="fa-solid ' . $ic . '"></i></span><div class="min-w-0 flex-1"><p class="font-bold">' . $l . '</p><p class="text-xs text-muted">' . money($s['amt'] ?? 0, true, true) . ' · ' . (int)$s['n'] . ' txns / 30 days' . ($s['un'] ? ' · <span class="text-rose-600 font-semibold">' . (int)$s['un'] . ' unmatched</span>' : '') . '</p></div>'
      . ($i && !$i['simulate'] ? pill('active', 'Live') : pill('pending', 'Simulate')) . '</a>'; }
echo '</div>';
[$label, $icon, $code, $tone] = $P[$tab] ?? $P['mpesa'];
$i = integration($code);
$acc = all("SELECT * FROM fin_accounts WHERE integration_code = ?", [$code]);
$txns = all("SELECT t.*, pm.uuid AS pay_uuid, pm.receipt_no, pm.status AS pay_status, pt.display_name FROM fin_gateway_transactions t LEFT JOIN fin_payments pm ON pm.id = t.payment_id LEFT JOIN prpt_parties pt ON pt.id = pm.party_id WHERE t.provider = ? ORDER BY t.transacted_at DESC LIMIT 100", [$tab]);
$base = rtrim((string)config('app.pms_url'), '/');
$hooks = match ($tab) { 'mpesa' => ['Confirmation URL' => "$base/webhooks/mpesa.php?action=confirm&i=$code", 'Validation URL' => "$base/webhooks/mpesa.php?action=validate&i=$code", 'STK callback' => "$base/webhooks/mpesa.php?action=stk&i=$code"],
    'coop' => ['IPN / notification URL' => "$base/webhooks/coop.php", 'Pull (cron)' => "$base/cron.php?token=…&job=coop"], 'stripe' => ['Webhook endpoint' => "$base/webhooks/stripe.php?i=$code"] };
?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">
  <div class="vk-panel overflow-hidden">
    <div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold"><i class="fa-solid <?= $icon ?> text-accent mr-2"></i><?= $label ?> transactions</h3>
      <?php if ($tab === 'coop'): ?><button class="ml-auto vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'finance/coop-pull'])) ?>"><i class="fa-solid fa-cloud-arrow-down"></i>Pull now</button><?php endif; ?></div>
    <div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>When</th><th>Transaction</th><th>Payer</th><th>Bill ref</th><th class="num">Amount</th><th>Match</th><th>Receipt</th></tr></thead><tbody>
    <?php foreach ($txns as $t): ?><tr>
      <td class="text-xs"><?= fdatetime($t['transacted_at']) ?></td>
      <td class="font-mono text-xs"><?= e($t['transaction_id']) ?><?= $t['is_simulated'] ? ' <span class="text-[10px] font-sans text-amber-600">SIM</span>' : '' ?></td>
      <td class="text-sm"><?= e($t['payer_name'] ?: '—') ?><span class="block text-xs text-muted"><?= e($t['payer_phone']) ?></span></td>
      <td class="font-mono text-xs"><?= e($t['bill_ref'] ?: '—') ?></td>
      <td class="num font-bold"><?= money($t['amount'], false) ?></td>
      <td><?= pill($t['match_status'] === 'matched' ? 'active' : ($t['match_status'] === 'unmatched' ? 'overdue' : 'ended'), ucfirst($t['match_status'])) ?><?= $t['display_name'] ? '<span class="block text-xs text-muted">' . e($t['display_name']) . '</span>' : '' ?></td>
      <td class="whitespace-nowrap"><?= $t['pay_uuid'] ? '<a class="text-accent font-semibold text-sm" href="' . e(page('finance/consume', ['payment' => $t['pay_uuid']])) . '">' . e($t['receipt_no']) . '</a> ' . pill($t['pay_status']) : '' ?>
        <?php if ($t['match_status'] === 'unmatched' && can('finance.allocate')): ?><button class="text-xs text-muted hover:text-rose-600 ml-1" data-action="<?= e(url('api.php', ['r' => 'finance/txn-ignore', 'id' => $t['id']])) ?>" data-confirm="Ignore this transaction (e.g. not a rent payment)?" title="Ignore"><i class="fa-solid fa-eye-slash"></i></button><?php endif; ?></td>
    </tr><?php endforeach; if (!$txns) echo '<tr><td colspan="7">' . ui_empty($icon, 'No transactions yet', 'Use the simulator to test the full flow end-to-end.') . '</td></tr>'; ?>
    </tbody></table></div>
  </div>
  <div class="space-y-4">
    <div class="vk-panel p-5">
      <h3 class="font-bold mb-2">Integration</h3>
      <p class="text-sm"><?= e($i['name'] ?? $code) ?> · <?= $i && !$i['simulate'] ? pill('active', 'Live · ' . e($i['environment'])) : pill('pending', 'Simulate mode') ?></p>
      <?php if ($i && $i['last_error']): ?><p class="mt-2 text-xs text-rose-600"><i class="fa-solid fa-triangle-exclamation"></i> <?= e($i['last_error']) ?></p><?php endif; ?>
      <p class="text-xs text-muted mt-1">Last success: <?= $i && $i['last_success_at'] ? time_ago($i['last_success_at']) : 'never' ?></p>
      <div class="mt-3 space-y-1 text-sm"><?php foreach ($acc as $x): ?><p><i class="fa-solid fa-building-columns text-muted mr-1"></i><b><?= e($x['name']) ?></b> · <?= e($x['number']) ?><?= $x['is_master'] ? ' ' . pill('active', 'Master') : '' ?></p><?php endforeach; if (!$acc) echo '<p class="text-xs text-amber-600">No collection account is linked to this feed yet.</p>'; ?></div>
      <div class="mt-4 flex flex-wrap gap-2"><?php if (can('config.integrations.manage')): ?><a class="vk-btn-ghost !py-1.5 text-xs" href="<?= e(page('config/integrations', ['code' => $code])) ?>"><i class="fa-solid fa-key"></i>Credentials</a><?php endif; ?>
        <?php if ($tab === 'mpesa' && can('config.integrations.manage')): ?><button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'finance/mpesa-register'])) ?>" data-no-reload><i class="fa-solid fa-link"></i>Register URLs</button><?php endif; ?></div>
      <div class="mt-4 space-y-2"><?php foreach ($hooks as $k => $v): ?><div><span class="text-[11px] font-bold uppercase text-muted"><?= e($k) ?></span><div class="flex gap-1"><input readonly class="vk-input !py-1.5 font-mono text-[11px]" value="<?= e($v) ?>"><button type="button" class="vk-icon-btn !h-9 !w-9 shrink-0" onclick="navigator.clipboard.writeText('<?= e($v) ?>');VK.toast('Copied')"><i class="fa-regular fa-copy text-xs"></i></button></div></div><?php endforeach; ?></div>
    </div>
    <form data-ajax="<?= e(url('api.php', ['r' => 'finance/simulate'])) ?>" class="vk-panel p-5 space-y-3">
      <input type="hidden" name="provider" value="<?= e($tab) ?>">
      <h3 class="font-bold"><i class="fa-solid fa-flask text-accent mr-2"></i>Simulate a payment</h3>
      <p class="text-xs text-muted">Runs the exact live pipeline: webhook → transaction → payment → match by account reference → auto-consume.</p>
      <div class="grid grid-cols-2 gap-3"><?= ui_field('Amount', ui_input('amount', 15000, 'number', 'step="any"')) ?><?= ui_field('Account ref (unit / lease no.)', ui_input('bill_ref', '', 'text', 'placeholder="e.g. A-101"')) ?>
        <?= ui_field('Phone', ui_input('phone', '0712345678')) ?><?= ui_field('Name', ui_input('name', 'JANE WANJIKU')) ?></div>
      <button class="vk-btn-accent w-full justify-center"><i class="fa-solid fa-bolt"></i>Send test payment</button>
    </form>
  </div>
</div>
