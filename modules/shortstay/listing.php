<?php
$l = one("SELECT s.*, u.unit_no, u.property_id, pr.name AS property FROM sst_listings s JOIN prpt_units u ON u.id = s.unit_id JOIN prpt_properties pr ON pr.id = u.property_id WHERE s.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$l) { echo ui_empty('fa-house-flag', 'Listing not found'); return; }
$id = (int)$l['id'];
$title = $l['title']; $subtitle = e($l['unit_no'] . ' · ' . $l['property']) . ' · ' . pill($l['status']);
$crumbs = [['Listings & rates', page('shortstay/listings')]];
$tab = $_GET['tab'] ?? 'settings';
$ical = rtrim((string)config('app.pms_url'), '/') . '/webhooks/ical.php?t=' . $l['ical_export_token'];
$actions = '<a class="vk-btn-ghost" href="' . e(page('shortstay/calendar')) . '"><i class="fa-solid fa-calendar-days"></i>Calendar</a>';
echo ui_tabs(['settings' => ['Settings', 'fa-sliders'], 'rates' => ['Seasonal rates', 'fa-sun'], 'channels' => ['Channels & iCal', 'fa-globe'], 'blocks' => ['Blocked dates', 'fa-ban']], $tab, 'tab', ['id' => $id]);
if ($tab === 'settings'): ?>
<form data-ajax="<?= e(url('api.php', ['r' => 'shortstay/save-listing'])) ?>" class="vk-panel p-6 space-y-5 max-w-5xl" data-stay>
  <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="unit_id" value="<?= $l['unit_id'] ?>">
  <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
    <?= ui_field('Title', ui_input('title', $l['title']), 'sm:col-span-3') ?><?= ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'paused' => 'Paused', 'archived' => 'Archived'], $l['status']))) ?>
    <?php foreach ([['base_rate', 'Nightly rate'], ['weekend_rate', 'Weekend rate (Fri/Sat)'], ['cleaning_fee', 'Cleaning fee'], ['security_deposit', 'Security deposit'], ['max_guests', 'Max guests'], ['beds', 'Beds'], ['min_nights', 'Min nights'], ['max_nights', 'Max nights'],
        ['extra_guest_fee', 'Extra guest fee / night'], ['extra_guest_after', 'Extra fee after (guests)'], ['weekly_discount_pct', 'Weekly discount %'], ['monthly_discount_pct', 'Monthly discount %'], ['tourism_levy_pct', 'Tourism levy %'], ['vat_pct', 'VAT %'], ['owner_share_pct', 'Owner share % of net']] as [$k, $lb]) echo ui_field($lb, ui_input($k, $l[$k], 'number', 'step="any"')); ?>
    <?= ui_field('Check-in from', ui_input('check_in_time', substr((string)$l['check_in_time'], 0, 5), 'time')) ?><?= ui_field('Check-out by', ui_input('check_out_time', substr((string)$l['check_out_time'], 0, 5), 'time')) ?>
    <?= ui_field('Wi-Fi name', ui_input('wifi_name', $l['wifi_name'])) ?><?= ui_field('Wi-Fi password', ui_input('wifi_password', $l['wifi_password'])) ?>
    <div class="sm:col-span-2"><?= ui_field('Access / check-in instructions (sent to guests)', '<textarea name="access_instructions" class="vk-textarea" rows="4">' . e($l['access_instructions']) . '</textarea>') ?></div>
    <div class="sm:col-span-2"><?= ui_field('House rules', '<textarea name="house_rules" class="vk-textarea" rows="4">' . e($l['house_rules']) . '</textarea>') ?></div>
  </div>
  <button class="vk-btn-accent"><i class="fa-solid fa-floppy-disk"></i>Save</button>
</form>
<?php elseif ($tab === 'rates'): echo render_crud('sst_rates', ['listing_id' => $id], ['title' => 'Seasonal rates', 'empty' => 'e.g. "December holidays" at a higher rate with a 3-night minimum.']);
elseif ($tab === 'channels'): ?>
  <div class="vk-panel p-5 mb-6"><p class="font-bold">Export calendar (give this URL to Airbnb, Booking.com, Vrbo…)</p><p class="text-xs text-muted mb-2">Every confirmed booking and blocked date is exported so the OTAs never double-book.</p>
    <div class="flex gap-2"><input readonly class="vk-input font-mono text-xs" value="<?= e($ical) ?>"><button type="button" class="vk-btn-ghost" onclick="navigator.clipboard.writeText('<?= e($ical) ?>');VK.toast('Copied')"><i class="fa-regular fa-copy"></i></button>
      <button class="vk-btn-dark" data-action="<?= e(url('api.php', ['r' => 'shortstay/ical-sync'])) ?>" data-payload='{"listing_id":<?= $id ?>}'><i class="fa-solid fa-rotate"></i>Import now</button></div></div>
  <?= render_crud('sst_channels', ['listing_id' => $id], ['title' => 'Channels', 'empty' => 'Add each OTA with its iCal import URL and commission.']) ?>
<?php else: $bl = all("SELECT * FROM sst_blocked_dates WHERE listing_id = ? AND end_date >= CURDATE() ORDER BY start_date", [$id]); ?>
  <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6 items-start">
    <div class="vk-panel overflow-hidden"><table class="vk-table"><thead><tr><th>From</th><th>To (checkout)</th><th>Reason</th><th>Note</th><th></th></tr></thead><tbody>
      <?php foreach ($bl as $x): ?><tr><td><?= fdate($x['start_date']) ?></td><td><?= fdate($x['end_date']) ?></td><td><?= e(str_replace('_', ' ', $x['reason'])) ?></td><td class="text-xs"><?= e($x['note']) ?></td><td class="text-right"><?php if ($x['reason'] !== 'ical_import'): ?><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'shortstay/unblock', 'id' => $x['id']])) ?>" data-confirm="Unblock?"><i class="fa-solid fa-xmark text-xs"></i></button><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table><?= $bl ? '' : ui_empty('fa-ban', 'No blocked dates') ?></div>
    <form data-ajax="<?= e(url('api.php', ['r' => 'shortstay/block'])) ?>" class="vk-panel p-5 space-y-3"><input type="hidden" name="listing_id" value="<?= $id ?>"><h3 class="font-bold">Block dates</h3>
      <div class="grid grid-cols-2 gap-3"><?= ui_field('From', ui_input('start_date', date('Y-m-d'), 'date')) ?><?= ui_field('To (last night)', ui_input('end_date', date('Y-m-d', strtotime('+2 days')), 'date')) ?></div>
      <?= ui_field('Reason', ui_select('reason', ui_kv_options(['owner_stay' => 'Owner stay', 'maintenance' => 'Maintenance', 'other' => 'Other']))) ?><?= ui_field('Note', ui_input('note')) ?>
      <button class="vk-btn-accent w-full justify-center">Block</button></form>
  </div>
<?php endif;
