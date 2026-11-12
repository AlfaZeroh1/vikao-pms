<?php
$title = 'Orders & payments';
$subtitle = 'Unit reservations paid on vikao.io, payment transactions and events sent to the PMS';
if (!(require __DIR__ . '/_guard.php')) return;
$tab = $_GET['tab'] ?? 'orders';
$newEv = (int)eval_("SELECT COUNT(*) FROM pms_events WHERE status <> 'acknowledged'");
echo ui_tabs(['orders' => ['Reservations', 'fa-bag-shopping'], 'payments' => ['Payment transactions', 'fa-money-bill-transfer'], 'events' => ['PMS events', 'fa-bell', $newEv]], $tab);
if ($tab === 'orders'): $st = (string)($_GET['status'] ?? ''); $rows = ecom_orders(array_filter(['status' => $st]), 300); ?>
  <div class="flex flex-wrap gap-2 mb-4"><?php foreach (['' => 'All', 'pending_payment' => 'Awaiting payment', 'paid' => 'Paid', 'processing' => 'Processing', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'] as $k => $l): ?><a class="vk-chip <?= $st === $k ? '!border-accent !bg-accent/10' : '' ?>" href="<?= e(page('ecommerce/orders', ['status' => $k])) ?>"><?= $l ?></a><?php endforeach; ?></div>
  <div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>Order</th><th>Customer</th><th>Listing / package</th><th class="num">Due</th><th class="num">Paid</th><th>Receipt</th><th>Status</th><th>PMS</th></tr></thead><tbody>
  <?php foreach ($rows as $o): $ls = json_decode((string)$o['listing_snapshot'], true) ?: []; $pk = json_decode((string)$o['package_snapshot'], true) ?: []; $lead = val("SELECT id FROM sls_leads WHERE ecom_order_number = ?", [$o['order_number']]); ?>
    <tr><td><b><?= e($o['order_number']) ?></b><span class="block text-xs text-muted"><?= fdatetime($o['created_at']) ?></span></td><td class="text-sm"><?= e($o['contact_name']) ?><span class="block text-xs text-muted"><?= e($o['contact_phone']) ?> · <?= e($o['contact_email']) ?></span></td>
      <td class="text-sm"><?= e($ls['title'] ?? '—') ?><span class="block text-xs text-muted"><?= e($pk['name'] ?? '') ?></span></td><td class="num"><?= money($o['amount_due'], false) ?></td><td class="num font-bold"><?= money($o['amount_paid'], false) ?></td>
      <td class="font-mono text-xs"><?= e($o['receipt'] ?: '—') ?><?= $o['simulated'] ? ' <span class="text-amber-600 font-sans">SIM</span>' : '' ?></td><td><?= pill($o['status']) ?></td>
      <td class="text-xs"><?= $lead ? '<a class="text-accent font-semibold" href="' . e(page('sales/lead', ['id' => $lead])) . '">Lead</a>' : pill($o['pms_sync_status']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?= $rows ? '' : ui_empty('fa-bag-shopping', 'No orders') ?></div>
<?php elseif ($tab === 'payments'): $rows = ecom_payments(300); ?>
  <div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>When</th><th>Order</th><th>Method</th><th>Phone</th><th>Receipt</th><th class="num">Amount</th><th>Result</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $p): ?><tr><td class="text-xs"><?= fdatetime($p['initiated_at']) ?></td><td><b><?= e($p['order_number']) ?></b><span class="block text-xs text-muted"><?= e($p['contact_name']) ?></span></td><td><?= e($p['method_name']) ?><?= $p['is_simulated'] ? ' <span class="text-[10px] text-amber-600">SIM</span>' : '' ?></td><td class="text-xs"><?= e($p['payer_phone']) ?></td><td class="font-mono text-xs"><?= e($p['provider_receipt'] ?: '—') ?></td><td class="num font-bold"><?= money($p['amount'], false) ?></td><td class="text-xs"><?= e($p['result_desc'] ?: '') ?></td><td><?= pill($p['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?= $rows ? '' : ui_empty('fa-money-bill', 'No payments') ?></div>
<?php else: $rows = eall("SELECT * FROM pms_events ORDER BY status = 'acknowledged', id DESC LIMIT 200"); ?>
  <p class="text-sm text-muted mb-3">The marketplace raises an event when a buyer pays a reservation. Acknowledging it creates a sales lead, reserves the unit and tells vikao.io the order is being processed.</p>
  <div class="vk-panel overflow-hidden"><table class="vk-table"><thead><tr><th>Event</th><th>Details</th><th>When</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $ev): $p = json_decode((string)$ev['payload'], true) ?: []; ?><tr><td><b><?= e($ev['event_type']) ?></b><span class="block text-xs text-muted">#<?= (int)$ev['id'] ?> · <?= e($ev['aggregate_type']) ?> <?= (int)$ev['aggregate_id'] ?></span></td>
    <td class="text-sm"><?= e(($p['order_number'] ?? '') . ' · ' . ($p['customer']['name'] ?? '') . ' · ' . ($p['listing']['title'] ?? '')) ?><?= isset($p['amount_paid']) ? '<span class="block text-xs text-muted">' . money($p['amount_paid']) . ' · ' . e($p['mpesa_receipt'] ?? '') . '</span>' : '' ?></td>
    <td class="text-xs"><?= fdatetime($ev['created_at']) ?></td><td><?= pill($ev['status']) ?></td>
    <td class="text-right"><?php if ($ev['status'] !== 'acknowledged'): ?><button class="vk-btn-accent !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'ecommerce/ack-event', 'id' => $ev['id']])) ?>"><i class="fa-solid fa-check"></i>Acknowledge</button><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table><?= $rows ? '' : ui_empty('fa-bell', 'No events yet') ?></div>
<?php endif;
