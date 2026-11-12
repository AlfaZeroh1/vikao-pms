<?php
require_once __DIR__ . '/_partials.php';
$title = 'Bookings';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'channel' => (string)($_GET['channel'] ?? ''), 'day' => (string)($_GET['day'] ?? ''), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? '')];
$w = ['1=1']; $p = [];
if ($f['q'] !== '') { $w[] = '(b.booking_no LIKE :q OR g.display_name LIKE :q OR g.phone LIKE :q OR b.channel_ref LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['status']) { $w[] = 'b.status = :s'; $p['s'] = $f['status']; }
if ($f['channel']) { $w[] = 'b.channel = :c'; $p['c'] = $f['channel']; }
if ($f['day'] === 'today') $w[] = "(b.check_in = CURDATE() OR b.check_out = CURDATE() OR b.status = 'checked_in')";
if ($f['from']) { $w[] = 'b.check_out >= :f'; $p['f'] = $f['from']; } if ($f['to']) { $w[] = 'b.check_in <= :t'; $p['t'] = $f['to']; }
$sql = "FROM sst_bookings b JOIN sst_listings s ON s.id = b.listing_id JOIN prpt_units u ON u.id = s.unit_id JOIN prpt_parties g ON g.id = b.guest_party_id LEFT JOIN bln_invoices i ON i.id = b.invoice_id WHERE " . implode(' AND ', $w);
[$lim, $off, $pager] = ui_paginate((int)val("SELECT COUNT(*) $sql", $p), 50);
$rows = all("SELECT b.*, s.title, u.unit_no, g.display_name, g.phone, i.balance, i.status AS inv_status $sql ORDER BY b.check_in DESC LIMIT $lim OFFSET $off", $p);
$chs = ['direct' => 'Direct', 'airbnb' => 'Airbnb', 'booking_com' => 'Booking.com', 'vrbo' => 'Vrbo', 'expedia' => 'Expedia', 'vikao' => 'vikao.io', 'walk_in' => 'Walk-in', 'other' => 'Other'];
if (can('shortstay.manage')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-book"><i class="fa-solid fa-plus"></i>New booking</button>';
?>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Booking, guest, phone, OTA ref"'), 'w-60') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['inquiry' => 'Inquiry', 'tentative' => 'Tentative', 'confirmed' => 'Confirmed', 'checked_in' => 'In house', 'checked_out' => 'Checked out', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'], $f['status'], 'Any')), 'w-40') ?>
  <?= ui_field('Channel', ui_select('channel', ui_kv_options($chs, $f['channel'], 'Any')), 'w-36') ?>
  <?= ui_field('Staying from', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('to', ui_input('to', $f['to'], 'date'), 'w-40') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Booking</th><th>Guest</th><th>Listing</th><th>Stay</th><th>Channel</th><th class="num">Total</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $b): ?><tr data-href="<?= e(page('shortstay/booking', ['id' => $b['id']])) ?>">
    <td><b><?= e($b['booking_no']) ?></b></td><td><?= e($b['display_name']) ?><span class="block text-xs text-muted"><?= e($b['phone']) ?></span></td><td class="text-sm"><?= e($b['title']) ?><span class="block text-xs text-muted"><?= e($b['unit_no']) ?></span></td>
    <td class="text-xs"><?= fdate($b['check_in'], 'j M') ?> → <?= fdate($b['check_out']) ?><span class="block text-muted"><?= (int)$b['nights'] ?> night(s) · <?= (int)$b['adults'] + (int)$b['children'] ?> guest(s)</span></td>
    <td class="text-sm"><?= e($chs[$b['channel']] ?? $b['channel']) ?></td><td class="num"><?= money($b['total'], false) ?></td><td class="num"><?= ui_money_cell($b['balance'] ?? 0, true) ?></td><td><?= pill($b['status']) ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="8">' . ui_empty('fa-suitcase-rolling', 'No bookings') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
<?= can('shortstay.manage') ? booking_modal_html() : '' ?>
