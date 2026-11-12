<?php
$title = 'Listings & rates';
$subtitle = 'Furnished units rented by the night — rates, seasons, OTA channels and iCal sync';
$rows = all("SELECT s.*, u.unit_no, pr.name AS property,
    (SELECT COUNT(*) FROM sst_bookings b WHERE b.listing_id = s.id AND b.status IN ('confirmed','checked_in') AND b.check_out >= CURDATE()) AS upcoming,
    (SELECT COALESCE(SUM(b.nights),0) FROM sst_bookings b WHERE b.listing_id = s.id AND b.status IN ('confirmed','checked_in','checked_out') AND b.check_in >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND b.check_in < CURDATE()) AS nights30,
    (SELECT AVG(review_rating) FROM sst_bookings b WHERE b.listing_id = s.id AND review_rating IS NOT NULL) AS rating
    FROM sst_listings s JOIN prpt_units u ON u.id = s.unit_id JOIN prpt_properties pr ON pr.id = u.property_id WHERE " . scope_sql('u.property_id') . " ORDER BY s.status = 'active' DESC, s.title");
if (can('shortstay.manage')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-listing"><i class="fa-solid fa-plus"></i>New listing</button>';
?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($rows as $l): ?>
  <a href="<?= e(page('shortstay/listing', ['id' => $l['id']])) ?>" class="vk-panel p-5 hover:border-accent/60 block <?= $l['status'] !== 'active' ? 'opacity-60' : '' ?>">
    <div class="flex items-start gap-3"><span class="grid h-11 w-11 place-items-center rounded-xl bg-accent/10 text-accent"><i class="fa-solid fa-bed"></i></span><div class="flex-1 min-w-0"><p class="font-bold truncate"><?= e($l['title']) ?></p><p class="text-xs text-muted"><?= e($l['unit_no']) ?> · <?= e($l['property']) ?></p></div><?= pill($l['status']) ?></div>
    <div class="mt-4 grid grid-cols-3 gap-2 text-center text-xs"><div class="rounded-xl bg-surface2/60 p-2"><b class="block text-sm"><?= money($l['base_rate'], true, true) ?></b>per night</div><div class="rounded-xl bg-surface2/60 p-2"><b class="block text-sm"><?= round((int)$l['nights30'] / 30 * 100) ?>%</b>occupancy 30 d</div><div class="rounded-xl bg-surface2/60 p-2"><b class="block text-sm"><?= (int)$l['upcoming'] ?></b>upcoming</div></div>
    <p class="mt-3 text-xs text-muted"><i class="fa-solid fa-user-group"></i> up to <?= (int)$l['max_guests'] ?> · min <?= (int)$l['min_nights'] ?> night(s)<?= $l['rating'] ? ' · <span class="text-amber-500">★ ' . num($l['rating'], 1) . '</span>' : '' ?></p>
  </a>
<?php endforeach; ?>
</div>
<?php if (!$rows) echo '<div class="vk-panel">' . ui_empty('fa-house-flag', 'No listings yet', 'Turn any furnished unit into a short-stay listing.') . '</div>';
if (can('shortstay.manage')) echo ui_modal('m-listing', 'New short-stay listing', '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">' . ui_field('Unit *', ui_unit_picker('unit_id', null, 'required'), 'sm:col-span-2') . ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'paused' => 'Paused'])))
  . ui_field('Title *', ui_input('title', '', 'text', 'required placeholder="e.g. Cosy 1-bed with skyline view"'), 'sm:col-span-3')
  . ui_field('Nightly rate *', ui_input('base_rate', '', 'number', 'required')) . ui_field('Weekend rate', ui_input('weekend_rate', '', 'number')) . ui_field('Cleaning fee', ui_input('cleaning_fee', 1500, 'number'))
  . ui_field('Max guests *', ui_input('max_guests', 2, 'number')) . ui_field('Beds', ui_input('beds', 1, 'number')) . ui_field('Min nights', ui_input('min_nights', 1, 'number'))
  . ui_field('Weekly discount %', ui_input('weekly_discount_pct', 10, 'number')) . ui_field('Monthly discount %', ui_input('monthly_discount_pct', 25, 'number')) . ui_field('Security deposit', ui_input('security_deposit', 0, 'number'))
  . ui_field('Tourism levy %', ui_input('tourism_levy_pct', 2, 'number', 'step="0.01"')) . ui_field('VAT %', ui_input('vat_pct', 0, 'number', 'step="0.01"')) . ui_field('Owner share % of net', ui_input('owner_share_pct', 80, 'number'))
  . ui_field('Check-in from', ui_input('check_in_time', '14:00', 'time')) . ui_field('Check-out by', ui_input('check_out_time', '10:00', 'time')) . '</div>', url('api.php', ['r' => 'shortstay/save-listing']), 'Create listing', 'max-w-3xl', 'fa-house-flag');
