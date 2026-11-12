<?php
$b = one("SELECT b.*, s.title, s.check_in_time, s.check_out_time, s.owner_share_pct, u.unit_no, u.id AS unit_id, pr.name AS property, g.display_name, g.phone, g.email, g.id_number, g.nationality, i.invoice_no, i.total AS inv_total, i.amount_paid, i.balance
    FROM sst_bookings b JOIN sst_listings s ON s.id = b.listing_id JOIN prpt_units u ON u.id = s.unit_id JOIN prpt_properties pr ON pr.id = u.property_id JOIN prpt_parties g ON g.id = b.guest_party_id LEFT JOIN bln_invoices i ON i.id = b.invoice_id WHERE b.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$b) { echo ui_empty('fa-suitcase-rolling', 'Booking not found'); return; }
$id = (int)$b['id'];
$title = 'Booking ' . $b['booking_no']; $subtitle = e($b['display_name'] . ' · ' . $b['title']) . ' · ' . pill($b['status']);
$crumbs = [['Bookings', page('shortstay/bookings')]];
$hk = all("SELECT h.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS who FROM sst_housekeeping h LEFT JOIN sys_users u ON u.id = h.assigned_to WHERE h.booking_id = ?", [$id]);
$m = can('shortstay.manage'); $api = url('api.php', ['r' => 'shortstay/booking-status']);
$a = [];
if ($m && in_array($b['status'], ['inquiry', 'tentative'], true)) $a[] = '<button class="vk-btn-accent" data-action="' . e($api) . '" data-payload=\'{"id":' . $id . ',"status":"confirmed"}\'><i class="fa-solid fa-check"></i>Confirm</button>';
if ($m && $b['status'] === 'confirmed') { $a[] = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'shortstay/send-checkin', 'id' => $id])) . '" data-no-reload><i class="fa-solid fa-paper-plane"></i>Send check-in info</button>'; $a[] = '<button class="vk-btn-accent" data-action="' . e($api) . '" data-payload=\'{"id":' . $id . ',"status":"checked_in"}\' data-prompt="Guest ID / passport number" data-prompt-name="id_number" data-prompt-value="' . e($b['id_number']) . '" data-prompt-optional="1"><i class="fa-solid fa-right-to-bracket"></i>Check in</button>'; }
if ($m && $b['status'] === 'checked_in') $a[] = '<button class="vk-btn-accent" data-action="' . e($api) . '" data-payload=\'{"id":' . $id . ',"status":"checked_out"}\' data-confirm="Check the guest out? A turnover clean is scheduled." data-icon="question"><i class="fa-solid fa-right-from-bracket"></i>Check out</button>';
if ($m && in_array($b['status'], ['inquiry', 'tentative', 'confirmed'], true)) { $a[] = '<button class="vk-btn-ghost !text-rose-600" data-action="' . e($api) . '" data-payload=\'{"id":' . $id . ',"status":"cancelled"}\' data-prompt="Cancellation reason"><i class="fa-solid fa-ban"></i>Cancel</button>'; if ($b['check_in'] <= date('Y-m-d')) $a[] = '<button class="vk-btn-ghost" data-action="' . e($api) . '" data-payload=\'{"id":' . $id . ',"status":"no_show"}\' data-confirm="Mark as no-show?">No-show</button>'; }
if ($b['invoice_id'] && (float)$b['balance'] > 0 && can('finance.payments.create')) $a[] = '<a class="vk-btn-ghost" href="' . e(page('finance/payments', ['new' => 1, 'invoice_id' => $b['invoice_id']])) . '"><i class="fa-solid fa-money-bill"></i>Payment</a>';
$actions = implode('', $a);
$net = (float)$b['total'] - (float)$b['taxes'] - (float)$b['channel_commission']; $owner = round2($net * (float)$b['owner_share_pct'] / 100);
?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">
  <div class="space-y-6">
    <div class="vk-panel p-6 grid grid-cols-1 sm:grid-cols-3 gap-5">
      <div><p class="text-xs text-muted">Check-in</p><p class="text-xl font-extrabold"><?= fdate($b['check_in'], 'D j M') ?></p><p class="text-xs text-muted">from <?= substr($b['check_in_time'], 0, 5) ?><?= $b['arrival_time'] ? ' · arriving ' . e($b['arrival_time']) : '' ?></p></div>
      <div><p class="text-xs text-muted">Check-out</p><p class="text-xl font-extrabold"><?= fdate($b['check_out'], 'D j M') ?></p><p class="text-xs text-muted">by <?= substr($b['check_out_time'], 0, 5) ?></p></div>
      <div><p class="text-xs text-muted">Stay</p><p class="text-xl font-extrabold"><?= (int)$b['nights'] ?> night<?= $b['nights'] > 1 ? 's' : '' ?></p><p class="text-xs text-muted"><?= (int)$b['adults'] ?> adult(s)<?= $b['children'] ? ', ' . (int)$b['children'] . ' child(ren)' : '' ?></p></div>
    </div>
    <div class="vk-panel p-6"><h3 class="font-bold mb-3">Charges</h3><dl class="text-sm space-y-1.5">
      <div class="flex justify-between"><dt>Accommodation</dt><dd><?= money($b['accommodation_total']) ?></dd></div>
      <?php if ((float)$b['discount'] > 0): ?><div class="flex justify-between text-emerald-600"><dt>Discount</dt><dd>−<?= money($b['discount']) ?></dd></div><?php endif; ?>
      <?php if ((float)$b['extra_fees'] > 0): ?><div class="flex justify-between"><dt>Extra guests</dt><dd><?= money($b['extra_fees']) ?></dd></div><?php endif; ?>
      <div class="flex justify-between"><dt>Cleaning</dt><dd><?= money($b['cleaning_fee']) ?></dd></div><div class="flex justify-between"><dt>Taxes &amp; tourism levy</dt><dd><?= money($b['taxes']) ?></dd></div>
      <div class="flex justify-between font-extrabold text-base border-t border-line pt-2"><dt>Total</dt><dd><?= money($b['total']) ?></dd></div>
      <?php if ((float)$b['channel_commission'] > 0): ?><div class="flex justify-between text-muted"><dt>OTA commission (<?= e($b['channel']) ?>)</dt><dd>−<?= money($b['channel_commission']) ?></dd></div><?php endif; ?>
      <div class="flex justify-between text-muted"><dt>Owner share (<?= num($b['owner_share_pct'], 0) ?>% of net)</dt><dd><?= money($owner) ?></dd></div>
      <?php if ((float)$b['security_deposit'] > 0): ?><div class="flex justify-between text-muted"><dt>Refundable deposit</dt><dd><?= money($b['security_deposit']) ?></dd></div><?php endif; ?></dl>
      <?php if ($b['invoice_id']): ?><a class="mt-4 flex items-center gap-3 rounded-xl border border-line p-3 hover:border-accent" href="<?= e(page('billing/invoice', ['id' => $b['invoice_id']])) ?>"><i class="fa-solid fa-file-invoice text-accent"></i><span class="flex-1"><b><?= e($b['invoice_no']) ?></b><span class="block text-xs text-muted">paid <?= money($b['amount_paid']) ?></span></span><?= (float)$b['balance'] > 0 ? '<b class="text-rose-600">' . money($b['balance']) . ' due</b>' : pill('paid') ?></a><?php endif; ?></div>
    <?php if ($b['special_requests'] || $b['cancel_reason']): ?><div class="vk-panel p-5 text-sm"><?= $b['special_requests'] ? '<p><b>Requests:</b> ' . e($b['special_requests']) . '</p>' : '' ?><?= $b['cancel_reason'] ? '<p class="text-rose-600"><b>Cancelled:</b> ' . e($b['cancel_reason']) . '</p>' : '' ?></div><?php endif; ?>
  </div>
  <aside class="space-y-4">
    <div class="vk-panel p-5"><div class="flex items-center gap-3"><?= ui_avatar($b['display_name']) ?><div><a class="font-bold hover:text-accent" href="<?= e(page('property/party', ['id' => $b['guest_party_id']])) ?>"><?= e($b['display_name']) ?></a><span class="block text-xs text-muted"><?= e($b['nationality']) ?> · ID <?= e($b['id_number'] ?: '—') ?></span></div></div>
      <p class="text-sm mt-3"><i class="fa-solid fa-phone text-muted w-5"></i><?= e($b['phone'] ?: '—') ?><br><i class="fa-solid fa-envelope text-muted w-5"></i><?= e($b['email'] ?: '—') ?></p>
      <p class="text-xs text-muted mt-3"><?= e(ucfirst(str_replace('_', ' ', $b['channel']))) ?><?= $b['channel_ref'] ? ' · ref ' . e($b['channel_ref']) : '' ?> · booked <?= fdate($b['created_at']) ?></p></div>
    <div class="vk-panel p-5"><h3 class="font-bold text-sm mb-2">Housekeeping</h3><?php foreach ($hk as $h): ?><div class="flex items-center gap-2 text-sm py-1"><i class="fa-solid fa-broom text-muted"></i><span class="flex-1"><?= e(ucfirst(str_replace('_', ' ', $h['task_type']))) ?> · <?= fdatetime($h['scheduled_for']) ?><span class="block text-xs text-muted"><?= e($h['who'] ?: 'unassigned') ?></span></span><?= pill($h['status']) ?></div><?php endforeach; if (!$hk) echo '<p class="text-xs text-muted">No tasks.</p>'; ?></div>
    <?php if (in_array($b['status'], ['checked_out'], true) && $m): ?>
    <form data-ajax="<?= e(url('api.php', ['r' => 'shortstay/review'])) ?>" class="vk-panel p-5 space-y-2"><input type="hidden" name="id" value="<?= $id ?>"><h3 class="font-bold text-sm">Guest review</h3>
      <?= ui_select('rating', ui_kv_options([5 => '★★★★★', 4 => '★★★★', 3 => '★★★', 2 => '★★', 1 => '★'], $b['review_rating'], '—')) ?><textarea name="text" class="vk-textarea" rows="2" placeholder="What did the guest say?"><?= e($b['review_text']) ?></textarea><button class="vk-btn-dark !py-2 w-full justify-center">Save</button></form>
    <?php endif; ?>
  </aside>
</div>
