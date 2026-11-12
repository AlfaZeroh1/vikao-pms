<?php
require_once __DIR__ . '/_partials.php';
$title = 'Reading assignments';
$subtitle = 'Send meter-reading rounds to field readers — they get a notification and email with a link to the reader app';
$st = (string)($_GET['status'] ?? 'open');
$w = [scope_sql('a.property_id')];
if ($st === 'open') $w[] = "a.status IN ('assigned','in_progress')"; elseif ($st) $w[] = 'a.status = ' . db()->quote($st);
if (!can('property.readings.approve')) $w[] = 'a.assigned_to = ' . staff_id();
$rows = all("SELECT a.*, pr.name AS property, bt.name AS bt_name, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS reader,
    (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id) AS total,
    (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id AND i.status = 'done') AS done,
    (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id AND i.status = 'skipped') AS skipped
    FROM prpt_reading_assignments a JOIN prpt_properties pr ON pr.id = a.property_id LEFT JOIN cfg_bill_types bt ON bt.id = a.bill_type_id JOIN sys_users u ON u.id = a.assigned_to
    WHERE " . implode(' AND ', $w) . " ORDER BY a.status IN ('assigned','in_progress') DESC, a.due_date LIMIT 200");
if (can('property.readings.approve')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-assign"><i class="fa-solid fa-plus"></i>New assignment</button>';
?>
<div class="flex flex-wrap gap-2 mb-4">
  <?php foreach (['open' => 'Open', 'completed' => 'Completed', 'cancelled' => 'Cancelled', '' => 'All'] as $k => $l): ?><a href="<?= e(page('property/assignments', ['status' => $k])) ?>" class="vk-chip <?= $st === $k ? '!border-accent !bg-accent/10' : '' ?>"><?= $l ?></a><?php endforeach; ?>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($rows as $a): $pct = $a['total'] ? round(($a['done'] + $a['skipped']) / $a['total'] * 100) : 0; $late = in_array($a['status'], ['assigned', 'in_progress'], true) && $a['due_date'] < date('Y-m-d'); ?>
  <div class="vk-panel p-5 flex flex-col">
    <div class="flex items-start gap-3">
      <span class="grid h-11 w-11 place-items-center rounded-xl bg-sky-500/10 text-sky-600"><i class="fa-solid fa-gauge-high"></i></span>
      <div class="min-w-0 flex-1"><p class="font-bold truncate"><?= e($a['title']) ?></p><p class="text-xs text-muted"><?= e($a['property']) ?> · <?= e($a['bt_name'] ?: 'All utilities') ?> · <?= e(month_label($a['period_month'])) ?></p></div>
      <?= pill($a['status']) ?>
    </div>
    <div class="mt-4"><div class="flex justify-between text-xs font-semibold mb-1"><span><?= $a['done'] ?> read · <?= $a['skipped'] ?> skipped · <?= $a['total'] ?> meters</span><span><?= $pct ?>%</span></div>
      <div class="h-2 rounded-full bg-surface2 overflow-hidden"><div class="h-full bg-accent rounded-full" style="width:<?= $pct ?>%"></div></div></div>
    <div class="mt-4 flex items-center gap-2 text-sm"><?= ui_avatar($a['reader'], 'h-7 w-7') ?><span class="font-semibold"><?= e($a['reader']) ?></span>
      <span class="ml-auto text-xs <?= $late ? 'text-rose-600 font-bold' : 'text-muted' ?>"><i class="fa-regular fa-calendar"></i> due <?= fdate($a['due_date']) ?></span></div>
    <div class="mt-4 pt-4 border-t border-line flex gap-2">
      <?php if (in_array($a['status'], ['assigned', 'in_progress'], true)): ?><a class="vk-btn-accent !py-2 text-sm flex-1 justify-center" href="<?= e(page('property/field-reading', ['assignment' => $a['id']])) ?>"><i class="fa-solid fa-mobile-screen"></i>Open in reader</a><?php endif; ?>
      <a class="vk-btn-ghost !py-2 text-sm" href="<?= e(page('property/readings', ['tab' => 'history', 'assignment' => $a['id'], 'property_id' => $a['property_id'], 'from' => date('Y-m-d', strtotime($a['created_at'] . ' -1 day'))])) ?>" title="Readings"><i class="fa-solid fa-list"></i></a>
      <?php if (can('property.readings.approve') && in_array($a['status'], ['assigned', 'in_progress'], true)): ?><button class="vk-btn-ghost !py-2 text-sm !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'property/cancel-assignment', 'id' => $a['id']])) ?>" data-confirm="Cancel this assignment?" title="Cancel"><i class="fa-solid fa-ban"></i></button><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$rows) echo '<div class="vk-panel">' . ui_empty('fa-clipboard-list', 'No assignments', 'Create one to send a reading round to a field reader.') . '</div>';
if (can('property.readings.approve')) {
    $props = properties_for_select(); $pid = (int)($props[0]['id'] ?? 0);
    $body = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Title *', ui_input('title', 'Water readings — ' . date('F Y'), 'text', 'required'), 'sm:col-span-2')
        . ui_field('Property *', ui_select('property_id', ui_options($props, 'id', 'name', $pid), 'id="as-prop" required'))
        . ui_field('Utility', ui_select('bill_type_id', ui_options(all("SELECT id, name FROM cfg_bill_types WHERE billing_method = 'metered' AND is_active = 1"), 'id', 'name', null, 'All metered utilities'), 'id="as-bt"'))
        . ui_field('Block', ui_select('block_id', '<option value="">All blocks</option>', 'id="as-block"'))
        . ui_field('Reader *', ui_select('assigned_to', ui_options(staff_for_select(), 'id', 'name', null, 'Choose…'), 'required'))
        . ui_field('Billing month', ui_input('period_month', date('Y-m'), 'month')) . ui_field('Due date *', ui_input('due_date', date('Y-m-d', strtotime('+3 days')), 'date', 'required'))
        . '<div class="sm:col-span-2"><span class="vk-label">Meters <span class="text-muted font-normal">(leave all unticked to include every active meter that matches)</span></span><div id="as-meters" class="max-h-56 overflow-y-auto rounded-xl border border-line p-2 grid grid-cols-1 sm:grid-cols-2 gap-1 text-sm"></div></div>'
        . '<div class="sm:col-span-2">' . ui_field('Instructions', ui_input('notes', '', 'text', 'placeholder="e.g. Caretaker has the meter-room key"')) . '</div>'
        . '<label class="vk-switch"><input type="hidden" name="require_photo" value="0"><input type="checkbox" name="require_photo" value="1" checked>Photo proof required</label></div>';
    echo ui_modal('m-assign', 'New reading assignment', $body, url('api.php', ['r' => 'property/create-assignment']), 'Assign & notify', 'max-w-2xl', 'fa-clipboard-list');
    ob_start(); ?>
<script>
function loadMeters() {
  const p = $('#as-prop').val();
  $.get(VK.url('api.php', { r: 'property/meters-for', property_id: p, bill_type_id: $('#as-bt').val(), block_id: $('#as-block').val() })).then(r => {
    $('#as-meters').html((r.items || []).map(m => `<label class="flex items-center gap-2 rounded-lg px-2 py-1 hover:bg-surface2"><input type="checkbox" name="meters[]" value="${m.id}" class="accent-amber-600"><b>${VK.esc(m.unit_no || 'Common')}</b><span class="text-xs text-muted truncate">${VK.esc(m.bt)} · ${VK.esc(m.serial_no)}</span></label>`).join('') || '<p class="text-muted p-2">No active meters</p>');
  });
}
$('#as-prop').on('change', function () { $.get(VK.url('api.php', { r: 'property/floors', property_id: this.value, blocks: 1 })).then(r => { $('#as-block').html('<option value="">All blocks</option>' + (r.blocks || []).map(b => `<option value="${b.id}">${VK.esc(b.name)}</option>`).join('')); }); loadMeters(); });
$('#as-bt, #as-block').on('change', loadMeters);
$(document).on('click', '[data-modal-open="#m-assign"]', () => $('#as-prop').trigger('change'));
</script>
<?php page_script(ob_get_clean()); }
