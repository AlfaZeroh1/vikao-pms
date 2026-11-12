<?php
$title = 'Housekeeping';
$subtitle = 'Turnover cleans are created automatically for every booking; add deep cleans, linen changes and inspections';
$day = input_date('day', date('Y-m-d')); $show = (string)($_GET['show'] ?? 'open');
$w = $show === 'open' ? "h.status IN ('pending','in_progress','issue') AND DATE(h.scheduled_for) <= DATE_ADD(:d, INTERVAL 7 DAY)" : 'DATE(h.scheduled_for) = :d';
$rows = all("SELECT h.*, s.title, u.unit_no, b.booking_no, b.check_in AS next_in, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS who,
    (SELECT MIN(nb.check_in) FROM sst_bookings nb WHERE nb.listing_id = h.listing_id AND nb.check_in >= DATE(h.scheduled_for) AND nb.status IN ('confirmed','tentative')) AS next_arrival
    FROM sst_housekeeping h JOIN sst_listings s ON s.id = h.listing_id JOIN prpt_units u ON u.id = s.unit_id LEFT JOIN sst_bookings b ON b.id = h.booking_id LEFT JOIN sys_users su ON su.id = h.assigned_to WHERE $w ORDER BY h.scheduled_for", ['d' => $day]);
$staff = staff_for_select();
if (can('shortstay.manage')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-hk"><i class="fa-solid fa-plus"></i>Task</button>';
?>
<div class="flex flex-wrap gap-2 mb-4 items-center"><a class="vk-chip <?= $show === 'open' ? '!border-accent !bg-accent/10' : '' ?>" href="<?= e(page('shortstay/housekeeping')) ?>">Open (next 7 days)</a>
  <form method="get" class="flex items-center gap-2"><input type="hidden" name="r" value="shortstay/housekeeping"><input type="hidden" name="show" value="day"><input type="date" name="day" value="<?= e($day) ?>" class="vk-input !py-1.5 !w-44" onchange="this.form.submit()"></form></div>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($rows as $h): $urgent = $h['next_arrival'] && $h['next_arrival'] <= date('Y-m-d', strtotime($h['scheduled_for'])); ?>
  <div class="vk-panel p-5 <?= $h['status'] === 'issue' ? '!border-rose-400' : '' ?>">
    <div class="flex items-start gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-sky-500/10 text-sky-600"><i class="fa-solid fa-broom"></i></span>
      <div class="flex-1"><b><?= e(ucfirst(str_replace('_', ' ', $h['task_type']))) ?> · <?= e($h['unit_no']) ?></b><p class="text-xs text-muted"><?= e($h['title']) ?><?= $h['booking_no'] ? ' · after ' . e($h['booking_no']) : '' ?></p></div><?= pill($h['status']) ?></div>
    <p class="mt-3 text-sm"><i class="fa-regular fa-clock text-muted"></i> <?= fdatetime($h['scheduled_for']) ?><?= $urgent ? ' <span class="text-rose-600 font-bold text-xs">· same-day arrival</span>' : ($h['next_arrival'] ? ' <span class="text-xs text-muted">· next guest ' . fdate($h['next_arrival']) . '</span>' : '') ?></p>
    <?php if ($h['notes']): ?><p class="mt-1 text-xs text-muted"><?= e($h['notes']) ?></p><?php endif; ?>
    <div class="mt-4 flex gap-2 items-center">
      <select class="vk-select !py-1.5 text-xs hk-assign" data-id="<?= $h['id'] ?>"><?= ui_options($staff, 'id', 'name', $h['assigned_to'], 'Assign…') ?></select>
      <?php if ($h['status'] !== 'done'): ?>
        <?php if ($h['status'] === 'pending'): ?><button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'shortstay/hk-update'])) ?>" data-payload='{"id":<?= $h['id'] ?>,"status":"in_progress"}'>Start</button><?php endif; ?>
        <button class="vk-btn-accent !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'shortstay/hk-update'])) ?>" data-payload='{"id":<?= $h['id'] ?>,"status":"done"}'>Done</button>
        <button class="vk-icon-btn !h-8 !w-8" title="Report issue" data-action="<?= e(url('api.php', ['r' => 'shortstay/hk-update'])) ?>" data-payload='{"id":<?= $h['id'] ?>,"status":"issue"}' data-prompt="What's the issue?" data-prompt-name="notes"><i class="fa-solid fa-triangle-exclamation text-rose-600 text-xs"></i></button>
      <?php endif; ?></div>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$rows) echo '<div class="vk-panel">' . ui_empty('fa-broom', 'Nothing scheduled') . '</div>';
echo ui_modal('m-hk', 'Housekeeping task', '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Listing', ui_select('listing_id', ui_options(all("SELECT id, title AS name FROM sst_listings WHERE status = 'active'"), 'id', 'name'))) . ui_field('Task', ui_select('task_type', ui_kv_options(['deep_clean' => 'Deep clean', 'linen_change' => 'Linen change', 'restock' => 'Restock', 'inspection' => 'Inspection', 'maintenance' => 'Maintenance', 'turnover' => 'Turnover'])))
  . ui_field('When', ui_input('scheduled_for', date('Y-m-d\T10:00'), 'datetime-local')) . ui_field('Assign to', ui_select('assigned_to', ui_options($staff, 'id', 'name', null, '—'))) . '<div class="sm:col-span-2">' . ui_field('Notes', ui_input('notes')) . '</div></div>', url('api.php', ['r' => 'shortstay/hk-create']), 'Schedule', 'max-w-xl', 'fa-broom');
page_script('<script>$(".hk-assign").on("change",function(){VK.api("shortstay/hk-update",{id:$(this).data("id"),assigned_to:this.value}).then(r=>VK.toast(r.message))})</script>');
