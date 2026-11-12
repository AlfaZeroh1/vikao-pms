<?php
require_once __DIR__ . '/_partials.php';
$title = 'Reader app';
$subtitle = 'Mobile meter reading with photo proof — works on any phone browser';
$aid = (int)($_GET['assignment'] ?? 0);
$mine = all("SELECT a.*, pr.name AS property, (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id) total, (SELECT COUNT(*) FROM prpt_reading_assignment_items i WHERE i.assignment_id = a.id AND i.status <> 'pending') done
    FROM prpt_reading_assignments a JOIN prpt_properties pr ON pr.id = a.property_id WHERE a.assigned_to = ? AND a.status IN ('assigned','in_progress') ORDER BY a.due_date", [staff_id()]);
$a = $aid ? one("SELECT a.*, pr.name AS property FROM prpt_reading_assignments a JOIN prpt_properties pr ON pr.id = a.property_id WHERE a.id = ?", [$aid]) : null;
$items = $a ? all("SELECT i.status AS item_status, i.skip_reason, m.id, m.serial_no, m.multiplier, m.initial_reading, u.unit_no, b.code AS block_code, f.name AS floor_name, bt.name AS bt, bt.unit_label, r.current_reading AS read_value, r.photo_path,
    (SELECT current_reading FROM prpt_meter_readings x WHERE x.meter_id = m.id AND x.status <> 'rejected' AND (i.reading_id IS NULL OR x.id <> i.reading_id) ORDER BY reading_date DESC, id DESC LIMIT 1) AS last_reading
    FROM prpt_reading_assignment_items i JOIN prpt_meters m ON m.id = i.meter_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id LEFT JOIN prpt_units u ON u.id = m.unit_id LEFT JOIN prpt_blocks b ON b.id = u.block_id LEFT JOIN prpt_floors f ON f.id = u.floor_id LEFT JOIN prpt_meter_readings r ON r.id = i.reading_id
    WHERE i.assignment_id = ? ORDER BY i.status = 'pending' DESC, b.code, f.level, u.unit_no", [$aid]) : [];
$needPhoto = $a ? (bool)$a['require_photo'] : (bool)setting('reading_photo_required', true);
?>
<div class="max-w-xl mx-auto">
<?php if (!$a): ?>
  <div class="vk-panel p-5 mb-5">
    <h3 class="font-bold mb-3"><i class="fa-solid fa-clipboard-list text-accent mr-2"></i>My assignments</h3>
    <?php foreach ($mine as $m): ?>
      <a href="<?= e(page('property/field-reading', ['assignment' => $m['id']])) ?>" class="flex items-center gap-3 rounded-xl border border-line p-3 mb-2 hover:border-accent/60">
        <span class="grid h-10 w-10 place-items-center rounded-xl bg-sky-500/10 text-sky-600"><i class="fa-solid fa-gauge-high"></i></span>
        <span class="flex-1 min-w-0"><b class="block truncate"><?= e($m['title']) ?></b><span class="text-xs text-muted"><?= e($m['property']) ?> · due <?= fdate($m['due_date']) ?></span></span>
        <b class="text-sm"><?= $m['done'] ?>/<?= $m['total'] ?></b><i class="fa-solid fa-chevron-right text-muted"></i>
      </a>
    <?php endforeach; if (!$mine) echo '<p class="text-sm text-muted">No open assignments. You can still read any meter below.</p>'; ?>
  </div>
  <div class="vk-panel p-5">
    <h3 class="font-bold mb-3"><i class="fa-solid fa-magnifying-glass text-accent mr-2"></i>Read any unit</h3>
    <?= ui_unit_picker('adhoc_unit', null, 'id="adhoc-unit"') ?>
    <div id="adhoc-meters" class="mt-3 space-y-2"></div>
  </div>
<?php else: $done = count(array_filter($items, fn($i) => $i['item_status'] !== 'pending')); ?>
  <div class="vk-panel p-5 mb-4">
    <div class="flex items-center gap-3"><a href="<?= e(page('property/field-reading')) ?>" class="vk-icon-btn"><i class="fa-solid fa-arrow-left"></i></a>
      <div class="min-w-0"><p class="font-bold truncate"><?= e($a['title']) ?></p><p class="text-xs text-muted"><?= e($a['property']) ?> · due <?= fdate($a['due_date']) ?></p></div>
      <b class="ml-auto text-lg" id="prog"><?= $done ?>/<?= count($items) ?></b></div>
    <div class="mt-3 h-2 rounded-full bg-surface2 overflow-hidden"><div class="h-full bg-accent" style="width:<?= count($items) ? round($done / count($items) * 100) : 0 ?>%"></div></div>
    <?php if ($a['notes']): ?><p class="mt-3 text-sm rounded-xl bg-amber-500/10 text-amber-800 dark:text-amber-300 p-3"><i class="fa-solid fa-circle-info mr-1"></i><?= e($a['notes']) ?></p><?php endif; ?>
    <input type="search" id="flt" class="vk-input mt-3" placeholder="Find unit or meter…">
  </div>
  <div class="space-y-2" id="list">
  <?php foreach ($items as $i): $prev = (float)($i['last_reading'] ?? $i['initial_reading']); ?>
    <button type="button" class="meter-item w-full text-left vk-panel p-4 flex items-center gap-3 <?= $i['item_status'] !== 'pending' ? 'opacity-60' : '' ?>" data-meter='<?= e(json_encode(['id' => (int)$i['id'], 'unit' => $i['unit_no'] ?? 'Common', 'serial' => $i['serial_no'], 'bt' => $i['bt'], 'label' => $i['unit_label'], 'prev' => $prev, 'mult' => (float)$i['multiplier'], 'status' => $i['item_status']])) ?>' data-search="<?= e(strtolower(($i['unit_no'] ?? '') . ' ' . $i['serial_no'])) ?>">
      <span class="grid h-11 w-11 place-items-center rounded-xl <?= $i['item_status'] === 'done' ? 'bg-emerald-500/10 text-emerald-600' : ($i['item_status'] === 'skipped' ? 'bg-slate-500/10 text-slate-500' : 'bg-accent/10 text-accent') ?>"><i class="fa-solid <?= $i['item_status'] === 'done' ? 'fa-check' : ($i['item_status'] === 'skipped' ? 'fa-forward' : 'fa-gauge') ?>"></i></span>
      <span class="flex-1 min-w-0"><b class="block"><?= e($i['unit_no'] ?? 'Common') ?> <span class="font-normal text-muted text-sm">· <?= e($i['bt']) ?></span></b><span class="text-xs text-muted"><?= e(trim(($i['block_code'] ? 'Block ' . $i['block_code'] . ' · ' : '') . ($i['floor_name'] ?? ''))) ?> · <?= e($i['serial_no']) ?></span></span>
      <span class="text-right text-sm"><?= $i['item_status'] === 'done' ? '<b>' . num($i['read_value'], 2) . '</b>' : ($i['item_status'] === 'skipped' ? '<span class="text-xs">' . e($i['skip_reason']) . '</span>' : '<span class="text-xs text-muted">prev</span><b class="block">' . num($prev, 2) . '</b>') ?></span>
    </button>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</div>

<!-- capture sheet -->
<div id="m-read" class="vk-modal hidden"><div class="vk-modal-backdrop" data-modal-close></div>
  <div class="vk-modal-card max-w-md"><form id="read-form" class="contents">
    <input type="hidden" name="meter_id"><input type="hidden" name="assignment_id" value="<?= $aid ?: '' ?>"><input type="hidden" name="photo_data"><input type="hidden" name="lat"><input type="hidden" name="lng">
    <div class="flex items-center gap-3 px-5 py-4 border-b border-line"><span class="grid h-9 w-9 place-items-center rounded-xl bg-accent/10 text-accent"><i class="fa-solid fa-gauge"></i></span>
      <div><h3 class="font-bold" id="rd-title">Unit</h3><p class="text-xs text-muted" id="rd-sub"></p></div><button type="button" class="ml-auto vk-icon-btn" data-modal-close><i class="fa-solid fa-xmark"></i></button></div>
    <div class="px-5 py-4 space-y-4">
      <label class="block" data-field-wrap><span class="vk-label">Meter photo <?= $needPhoto ? '<span class="text-accent">*</span>' : '(optional)' ?></span>
        <input type="file" accept="image/*" capture="environment" id="rd-cam" class="hidden">
        <button type="button" id="rd-shoot" class="w-full rounded-2xl border-2 border-dashed border-line aspect-video grid place-items-center overflow-hidden text-muted hover:border-accent" data-field="photo">
          <span id="rd-ph"><i class="fa-solid fa-camera text-3xl"></i><span class="block text-sm mt-1">Tap to take photo</span></span><img id="rd-img" class="hidden w-full h-full object-cover" alt="">
        </button></label>
      <div class="grid grid-cols-2 gap-3">
        <div class="rounded-xl bg-surface2 p-3"><span class="text-xs text-muted">Previous</span><b class="block text-lg tabular-nums" id="rd-prev">0</b></div>
        <div class="rounded-xl bg-surface2 p-3"><span class="text-xs text-muted">Consumption</span><b class="block text-lg tabular-nums" id="rd-cons">—</b></div>
      </div>
      <label class="block" data-field-wrap><span class="vk-label">Current reading *</span><input name="reading" inputmode="decimal" class="vk-input !text-2xl !py-3 text-center font-extrabold tabular-nums" autocomplete="off" required></label>
      <div class="grid grid-cols-2 gap-3">
        <label class="block" data-field-wrap><span class="vk-label">Date</span><input type="date" name="reading_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="vk-input"></label>
        <label class="block"><span class="vk-label">Note</span><input name="notes" class="vk-input" placeholder="optional"></label>
      </div>
      <label class="vk-switch text-sm hidden" id="rd-roll"><input type="checkbox" name="rollover" value="1">Meter rolled over / was replaced</label>
      <p class="text-xs text-muted" id="rd-geo"><i class="fa-solid fa-location-dot"></i> Getting location…</p>
    </div>
    <div class="flex gap-2 px-5 py-4 border-t border-line bg-surface2/40">
      <?php if ($aid): ?><button type="button" class="vk-btn-ghost" id="rd-skip"><i class="fa-solid fa-forward"></i>Skip</button><?php endif; ?>
      <button class="vk-btn-accent flex-1 justify-center" id="rd-save"><i class="fa-solid fa-check"></i>Save &amp; next</button>
    </div>
  </form></div>
</div>
<?php ob_start(); ?>
<script>
(function () {
  let cur = null;
  const $f = $('#read-form');
  function open(m) {
    cur = m; $f[0].reset(); $f.find('.vk-error').remove(); $f.find('.is-invalid').removeClass('is-invalid');
    $f.find('[name=meter_id]').val(m.id); $f.find('[name=photo_data]').val(''); $('#rd-img').addClass('hidden'); $('#rd-ph').removeClass('hidden');
    $('#rd-title').text(m.unit + ' · ' + m.bt); $('#rd-sub').text('Meter ' + m.serial); $('#rd-prev').text(Number(m.prev).toFixed(2)); $('#rd-cons').text('—'); $('#rd-roll').addClass('hidden');
    VK.openModal('#m-read');
    if (navigator.geolocation) navigator.geolocation.getCurrentPosition(p => { $f.find('[name=lat]').val(p.coords.latitude.toFixed(7)); $f.find('[name=lng]').val(p.coords.longitude.toFixed(7)); $('#rd-geo').html('<i class="fa-solid fa-location-dot text-emerald-500"></i> Location captured'); }, () => $('#rd-geo').text('Location not available'), { timeout: 6000 });
  }
  $(document).on('click', '.meter-item', function () { open($(this).data('meter')); });
  $('#flt').on('input', function () { const q = this.value.toLowerCase(); $('.meter-item').each(function () { $(this).toggle($(this).data('search').includes(q)); }); });
  $f.find('[name=reading]').on('input', function () {
    if (!cur) return; const v = VK.num(this.value), c = (v - cur.prev) * (cur.mult || 1);
    $('#rd-cons').text(this.value ? c.toFixed(2) + ' ' + (cur.label || '') : '—').toggleClass('text-rose-600', c < 0); $('#rd-roll').toggleClass('hidden', !(this.value && c < 0));
  });
  $('#rd-shoot').on('click', () => $('#rd-cam').trigger('click'));
  $('#rd-cam').on('change', function () {
    const file = this.files[0]; if (!file) return;
    const img = new Image(), rd = new FileReader();
    rd.onload = e => { img.onload = () => { const max = 1280, s = Math.min(1, max / Math.max(img.width, img.height)), c = document.createElement('canvas'); c.width = img.width * s; c.height = img.height * s; c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      const d = c.toDataURL('image/jpeg', 0.78); $f.find('[name=photo_data]').val(d); $('#rd-img').attr('src', d).removeClass('hidden'); $('#rd-ph').addClass('hidden'); $f.find('[data-field=photo]').removeClass('is-invalid').closest('[data-field-wrap]').find('.vk-error').remove(); }; img.src = e.target.result; };
    rd.readAsDataURL(file); this.value = '';
  });
  function next() { const $n = $('.meter-item').filter((i, x) => $(x).data('meter').status === 'pending' && $(x).data('meter').id !== cur.id).first(); VK.closeModal($('#m-read')); if ($n.length) setTimeout(() => open($n.data('meter')), 250); else setTimeout(() => location.reload(), 300); }
  function markDone(st, label) { const $b = $('.meter-item').filter((i, x) => $(x).data('meter').id === cur.id); const m = $b.data('meter'); m.status = st; $b.data('meter', m).addClass('opacity-60').find('span:last').html('<b>' + VK.esc(label) + '</b>'); const d = $('.meter-item').filter((i, x) => $(x).data('meter').status !== 'pending').length; $('#prog').text(d + '/' + $('.meter-item').length); }
  $f.on('submit', function (e) {
    e.preventDefault(); const $b = $('#rd-save'); VK.btnLoading($b, true, 'Saving');
    VK.api('property/field-reading', $f.serialize(), { silent: true }).then(r => { VK.btnLoading($b, false); VK.toast(r.message, r.reading && r.reading.anomaly_flag ? 'warning' : 'success');
      if ($('.meter-item').length) { markDone('done', Number(r.reading.current_reading).toFixed(2)); next(); } else { VK.closeModal($('#m-read')); $('#adhoc-unit').trigger('change'); } })
      .catch(j => { VK.btnLoading($b, false); VK.showErrors($f, (j && j.errors) || {}); VK.toast((j && j.message) || 'Could not save', 'error'); });
  });
  $('#rd-skip').on('click', async function () {
    const r = await Swal.fire({ title: 'Why skip this meter?', input: 'select', inputOptions: { 'No access': 'No access', 'Meter broken': 'Meter broken / unreadable', 'Meter missing': 'Meter missing', 'Tenant refused': 'Tenant refused', 'Other': 'Other' }, showCancelButton: true });
    if (!r.isConfirmed) return;
    VK.api('property/skip-meter', { assignment_id: <?= $aid ?: 0 ?>, meter_id: cur.id, reason: r.value }).then(() => { markDone('skipped', r.value); next(); });
  });
  $('#adhoc-unit').on('change', function () {
    if (!this.value) return $('#adhoc-meters').empty();
    $.get(VK.url('api.php', { r: 'property/meters-for', unit_id: this.value })).then(r => {
      $('#adhoc-meters').html((r.items || []).map(m => { const d = { id: +m.id, unit: m.unit_no, serial: m.serial_no, bt: m.bt, label: m.unit_label, prev: +(m.last_reading ?? m.initial_reading), mult: +m.multiplier, status: 'adhoc' };
        return `<button type="button" class="adhoc w-full text-left rounded-xl border border-line p-3 flex items-center gap-3 hover:border-accent" data-meter='${JSON.stringify(d).replace(/'/g, '&#39;')}'><i class="fa-solid fa-gauge text-accent"></i><span class="flex-1"><b>${VK.esc(m.bt)}</b><span class="block text-xs text-muted">${VK.esc(m.serial_no)}</span></span><span class="text-sm">prev <b>${d.prev.toFixed(2)}</b></span></button>`; }).join('') || '<p class="text-sm text-muted">No active meters on this unit.</p>');
    });
  });
  $(document).on('click', '.adhoc', function () { open($(this).data('meter')); });
})();
</script>
<?php page_script(ob_get_clean());
