<?php
require_once __DIR__ . '/_partials.php';
$title = 'Short-stay calendar';
$ym = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$start = $ym . '-01'; $days = (int)date('t', strtotime($start)); $end = date('Y-m-d', strtotime("$start +$days day"));
$listings = all("SELECT s.*, u.unit_no, pr.name AS property FROM sst_listings s JOIN prpt_units u ON u.id = s.unit_id JOIN prpt_properties pr ON pr.id = u.property_id WHERE s.status = 'active' AND " . scope_sql('u.property_id') . " ORDER BY pr.name, s.title");
$bk = all("SELECT b.*, p.display_name FROM sst_bookings b JOIN prpt_parties p ON p.id = b.guest_party_id WHERE b.status IN ('inquiry','tentative','confirmed','checked_in','checked_out') AND b.check_in < ? AND b.check_out > ?", [$end, $start]);
$bl = all("SELECT * FROM sst_blocked_dates WHERE start_date < ? AND end_date > ?", [$end, $start]);
$occNights = 0; $revenue = 0;
foreach ($bk as $b) { if (in_array($b['status'], ['inquiry'], true)) continue; $s = max($b['check_in'], $start); $e = min($b['check_out'], $end); $n = max(0, (strtotime($e) - strtotime($s)) / 86400); $occNights += $n; $revenue += $b['nights'] ? (float)$b['accommodation_total'] / (int)$b['nights'] * $n : 0; }
$avail = max(1, count($listings) * $days);
$prev = date('Y-m', strtotime("$start -1 month")); $next = date('Y-m', strtotime("$start +1 month"));
$arrivals = (int)val("SELECT COUNT(*) FROM sst_bookings WHERE check_in = CURDATE() AND status IN ('confirmed','tentative')"); $departures = (int)val("SELECT COUNT(*) FROM sst_bookings WHERE check_out = CURDATE() AND status = 'checked_in'");
$subtitle = month_label($ym);
$actions = '<a class="vk-btn-ghost" href="' . e(page('shortstay/calendar', ['month' => $prev])) . '"><i class="fa-solid fa-chevron-left"></i></a><a class="vk-btn-ghost" href="' . e(page('shortstay/calendar')) . '">Today</a><a class="vk-btn-ghost" href="' . e(page('shortstay/calendar', ['month' => $next])) . '"><i class="fa-solid fa-chevron-right"></i></a>' . (can('shortstay.manage') ? '<button class="vk-btn-accent" data-modal-open="#m-book"><i class="fa-solid fa-plus"></i>Booking</button>' : '');
$colors = ['confirmed' => 'bg-emerald-500', 'checked_in' => 'bg-sky-500', 'checked_out' => 'bg-slate-400', 'tentative' => 'bg-amber-400', 'inquiry' => 'bg-violet-300'];
?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
  <?= ui_stat('fa-bed', 'Occupancy', round($occNights / $avail * 100) . '%', 'accent', num($occNights) . ' of ' . num($avail) . ' nights') ?>
  <?= ui_stat('fa-sack-dollar', 'Room revenue', money($revenue, true, true), 'green') ?>
  <?= ui_stat('fa-tag', 'ADR', money($occNights ? $revenue / $occNights : 0, true, true), 'blue', 'avg daily rate') ?>
  <?= ui_stat('fa-chart-line', 'RevPAR', money($revenue / $avail, true, true), 'violet') ?>
  <?= ui_stat('fa-plane-arrival', 'Today', $arrivals . ' in · ' . $departures . ' out', 'slate', null, page('shortstay/bookings', ['day' => 'today'])) ?>
</div>
<div class="vk-panel overflow-x-auto">
  <table class="min-w-max w-full text-xs border-collapse select-none">
    <thead><tr><th class="sticky left-0 z-10 bg-surface text-left px-3 py-2 w-56 border-b border-line">Listing</th>
      <?php for ($d = 1; $d <= $days; $d++): $dt = sprintf('%s-%02d', $ym, $d); $wk = in_array(date('N', strtotime($dt)), ['6', '7'], true); ?>
        <th class="w-9 py-1 border-b border-l border-line font-semibold <?= $dt === date('Y-m-d') ? 'bg-accent text-white' : ($wk ? 'bg-surface2/70' : '') ?>"><span class="block text-[9px] font-normal opacity-70"><?= date('D', strtotime($dt))[0] ?></span><?= $d ?></th>
      <?php endfor; ?></tr></thead>
    <tbody>
    <?php foreach ($listings as $l): ?>
      <tr class="h-11">
        <td class="sticky left-0 z-10 bg-surface px-3 border-b border-line"><a class="font-bold hover:text-accent" href="<?= e(page('shortstay/listing', ['id' => $l['id']])) ?>"><?= e($l['title']) ?></a><span class="block text-[10px] text-muted"><?= e($l['unit_no']) ?> · <?= money($l['base_rate'], true, true) ?>/night</span></td>
        <td colspan="<?= $days ?>" class="relative border-b border-line p-0">
          <div class="absolute inset-0 grid" style="grid-template-columns:repeat(<?= $days ?>,minmax(0,1fr))">
            <?php for ($d = 1; $d <= $days; $d++): $dt = sprintf('%s-%02d', $ym, $d); ?><button type="button" class="border-l border-line/60 hover:bg-accent/10 <?= in_array(date('N', strtotime($dt)), ['6', '7'], true) ? 'bg-surface2/40' : '' ?>" <?= can('shortstay.manage') && $dt >= date('Y-m-d') ? 'data-book data-listing="' . $l['id'] . '" data-date="' . $dt . '"' : '' ?> title="<?= fdate($dt) ?>"></button><?php endfor; ?>
          </div>
          <?php foreach ($bl as $x) if ((int)$x['listing_id'] === (int)$l['id']): $s = max(1, (int)((strtotime($x['start_date']) - strtotime($start)) / 86400) + 1); $e = min($days + 1, (int)((strtotime($x['end_date']) - strtotime($start)) / 86400) + 1); ?>
            <div class="absolute top-1.5 bottom-1.5 rounded-md bg-[repeating-linear-gradient(45deg,#cbd5e1,#cbd5e1_4px,#e2e8f0_4px,#e2e8f0_8px)] text-[10px] text-slate-600 px-1 truncate pointer-events-none" style="left:<?= ($s - 1) / $days * 100 ?>%;width:<?= ($e - $s) / $days * 100 ?>%" title="Blocked: <?= e($x['reason'] . ' ' . $x['note']) ?>"><?= e(str_replace('_', ' ', $x['reason'])) ?></div>
          <?php endif; ?>
          <?php foreach ($bk as $b) if ((int)$b['listing_id'] === (int)$l['id']): $s = (strtotime(max($b['check_in'], $start)) - strtotime($start)) / 86400; $e = (strtotime(min($b['check_out'], $end)) - strtotime($start)) / 86400; ?>
            <a href="<?= e(page('shortstay/booking', ['id' => $b['id']])) ?>" class="absolute top-1.5 bottom-1.5 rounded-md <?= $colors[$b['status']] ?? 'bg-slate-400' ?> text-white text-[10px] font-semibold px-1.5 flex items-center truncate shadow hover:ring-2 ring-white" style="left:calc(<?= ($s + ($b['check_in'] >= $start ? 0.5 : 0)) / $days * 100 ?>%);width:calc(<?= ($e - $s - ($b['check_in'] >= $start ? 0.5 : 0) + ($b['check_out'] <= $end ? 0.5 : 0)) / $days * 100 ?>% - 2px)" title="<?= e($b['display_name'] . ' · ' . $b['booking_no'] . ' · ' . $b['channel']) ?>"><?= e($b['display_name']) ?></a>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; if (!$listings) echo '<tr><td colspan="' . ($days + 1) . '">' . ui_empty('fa-house-flag', 'No short-stay listings yet', '', '<a class="vk-btn-accent" href="' . e(page('shortstay/listings')) . '">Create a listing</a>') . '</td></tr>'; ?>
    </tbody>
  </table>
</div>
<div class="flex flex-wrap gap-4 mt-3 text-xs text-muted"><?php foreach (['confirmed' => 'Confirmed', 'checked_in' => 'In house', 'checked_out' => 'Checked out', 'tentative' => 'Tentative', 'inquiry' => 'Inquiry'] as $k => $l): ?><span class="flex items-center gap-1.5"><span class="h-3 w-5 rounded <?= $colors[$k] ?>"></span><?= $l ?></span><?php endforeach; ?><span class="flex items-center gap-1.5"><span class="h-3 w-5 rounded bg-slate-300"></span>Blocked / OTA</span><span>Click an empty day to book</span></div>
<?= can('shortstay.manage') ? booking_modal_html() : '' ?>
