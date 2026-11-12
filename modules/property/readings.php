<?php
require_once __DIR__ . '/_partials.php';
$title = 'Meter readings';
$subtitle = 'Mass entry by block / floor, approvals and history';
$tab = $_GET['tab'] ?? 'entry';
$props = properties_for_select();
$pid = (int)($_GET['property_id'] ?? ($props[0]['id'] ?? 0));
$btId = (int)($_GET['bill_type_id'] ?? 0);
$blockId = (int)($_GET['block_id'] ?? 0); $floorId = (int)($_GET['floor_id'] ?? 0);
$metered = all("SELECT id, name, unit_label FROM cfg_bill_types WHERE billing_method = 'metered' AND is_active = 1 ORDER BY sort_order");
$pending = (int)val("SELECT COUNT(*) FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id WHERE r.status = 'pending' AND " . scope_sql('m.property_id'));
$actions = '<a class="vk-btn-ghost" href="' . e(page('property/assignments')) . '"><i class="fa-solid fa-clipboard-list"></i>Assignments</a><a class="vk-btn-accent" href="' . e(page('property/field-reading')) . '"><i class="fa-solid fa-mobile-screen"></i>Reader app</a>';
echo ui_tabs(['entry' => ['Mass entry', 'fa-table-cells'], 'pending' => ['Pending approval', 'fa-hourglass-half', $pending], 'history' => ['History', 'fa-clock-rotate-left']], $tab, 'tab', array_filter(['property_id' => $pid]));

if ($tab === 'entry'):
    $blocks = $pid ? all("SELECT id, CONCAT(code, ' — ', name) name FROM prpt_blocks WHERE property_id = ? ORDER BY sort_order, code", [$pid]) : [];
    $floors = $pid ? all("SELECT f.id, CONCAT(COALESCE(CONCAT(b.code, ' · '), ''), f.name) name FROM prpt_floors f LEFT JOIN prpt_blocks b ON b.id = f.block_id WHERE f.property_id = ?" . ($blockId ? ' AND f.block_id = ' . $blockId : '') . " ORDER BY b.code, f.level", [$pid]) : [];
    $w = ['m.property_id = :p', "m.status = 'active'"]; $p = ['p' => $pid];
    if ($btId) { $w[] = 'm.bill_type_id = :bt'; $p['bt'] = $btId; }
    if ($blockId) { $w[] = 'u.block_id = :b'; $p['b'] = $blockId; }
    if ($floorId) { $w[] = 'u.floor_id = :f'; $p['f'] = $floorId; }
    $meters = $pid ? all("SELECT m.*, u.unit_no, bt.name AS bt_name, bt.unit_label, b.code AS block_code, f.name AS floor_name,
        (SELECT current_reading FROM prpt_meter_readings r WHERE r.meter_id = m.id AND r.status <> 'rejected' ORDER BY reading_date DESC, id DESC LIMIT 1) AS last_reading,
        (SELECT reading_date FROM prpt_meter_readings r WHERE r.meter_id = m.id AND r.status <> 'rejected' ORDER BY reading_date DESC, id DESC LIMIT 1) AS last_date,
        (SELECT AVG(consumption) FROM (SELECT consumption FROM prpt_meter_readings r WHERE r.meter_id = m.id AND r.status <> 'rejected' ORDER BY reading_date DESC LIMIT 3) x) AS avg_cons
        FROM prpt_meters m JOIN cfg_bill_types bt ON bt.id = m.bill_type_id LEFT JOIN prpt_units u ON u.id = m.unit_id LEFT JOIN prpt_blocks b ON b.id = u.block_id LEFT JOIN prpt_floors f ON f.id = u.floor_id
        WHERE " . implode(' AND ', $w) . " ORDER BY b.code, f.level, u.unit_no, bt.sort_order", $p) : []; ?>
  <?= ui_filter_open() ?>
    <input type="hidden" name="tab" value="entry">
    <?= ui_field('Property', ui_select('property_id', ui_options($props, 'id', 'name', $pid)), 'w-56') ?>
    <?= ui_field('Utility', ui_select('bill_type_id', ui_options($metered, 'id', 'name', $btId, 'All metered')), 'w-40') ?>
    <?= ui_field('Block', ui_select('block_id', ui_options($blocks, 'id', 'name', $blockId, 'All blocks')), 'w-44') ?>
    <?= ui_field('Floor', ui_select('floor_id', ui_options($floors, 'id', 'name', $floorId, 'All floors')), 'w-44') ?>
  <?= ui_filter_close() ?>
  <form data-ajax="<?= e(url('api.php', ['r' => 'property/bulk-readings'])) ?>" id="bulk-form" class="vk-panel overflow-hidden" data-stay>
    <div class="flex flex-wrap items-center gap-3 px-5 py-3.5 border-b border-line">
      <label class="flex items-center gap-2 text-sm font-semibold">Reading date <input type="date" name="reading_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" class="vk-input !w-44 !py-2"></label>
      <span class="text-sm text-muted"><b id="filled">0</b> of <?= count($meters) ?> entered · Tab / Enter moves down</span>
      <button class="ml-auto vk-btn-accent"><i class="fa-solid fa-floppy-disk"></i>Save readings</button>
    </div>
    <div class="overflow-x-auto"><table class="vk-table" id="grid">
      <thead><tr><th>Unit</th><th>Meter</th><th>Utility</th><th class="num">Previous</th><th>Last read</th><th class="w-44">Current reading</th><th class="num">Consumption</th><th class="num">3-mo avg</th></tr></thead>
      <tbody>
      <?php foreach ($meters as $i => $m): ?>
        <tr data-prev="<?= (float)($m['last_reading'] ?? $m['initial_reading']) ?>" data-avg="<?= (float)$m['avg_cons'] ?>" data-mult="<?= (float)$m['multiplier'] ?>">
          <td><b><?= e($m['unit_no'] ?? 'Common') ?></b><span class="block text-xs text-muted"><?= e(trim(($m['block_code'] ? 'Blk ' . $m['block_code'] : '') . ' ' . ($m['floor_name'] ?? ''))) ?></span></td>
          <td class="font-mono text-xs"><?= e($m['serial_no']) ?></td>
          <td><?= e($m['bt_name']) ?></td>
          <td class="num"><?= num($m['last_reading'] ?? $m['initial_reading'], 2) ?></td>
          <td class="text-xs text-muted"><?= $m['last_date'] ? fdate($m['last_date']) : 'Initial' ?></td>
          <td><div data-field-wrap><input name="r[<?= $m['id'] ?>]" inputmode="decimal" class="vk-input !py-1.5 text-right font-semibold tabular-nums reading-in" tabindex="<?= $i + 1 ?>" autocomplete="off"></div></td>
          <td class="num cons text-muted">—</td>
          <td class="num text-xs text-muted"><?= $m['avg_cons'] ? num($m['avg_cons'], 1) . ' ' . e($m['unit_label']) : '—' ?></td>
        </tr>
      <?php endforeach; if (!$meters) echo '<tr><td colspan="8">' . ui_empty('fa-gauge', 'No active meters', 'Add meters on the property page (Meters tab).') . '</td></tr>'; ?>
      </tbody></table></div>
  </form>
  <?php ob_start(); ?>
  <script>
  $('#grid').on('input', '.reading-in', function () {
    const $tr = $(this).closest('tr'), v = this.value.trim(), prev = +$tr.data('prev'), avg = +$tr.data('avg'), mult = +$tr.data('mult') || 1, $c = $tr.find('.cons');
    $(this).removeClass('is-invalid'); $tr.find('.vk-error').remove();
    if (v === '') { $c.text('—').attr('class', 'num cons text-muted'); }
    else { const cons = (VK.num(v) - prev) * mult; let cls = 'num cons font-bold ';
      if (cons < 0) cls += 'text-rose-600'; else if (avg > 0 && cons > avg * 2.5) cls += 'text-amber-600'; else cls += 'text-emerald-600';
      $c.html(cons.toFixed(2) + (cons < 0 ? ' <i class="fa-solid fa-triangle-exclamation" title="Lower than previous"></i>' : (avg > 0 && cons > avg * 2.5 ? ' <i class="fa-solid fa-bolt" title="Unusually high"></i>' : ''))).attr('class', cls); }
    $('#filled').text($('.reading-in').filter((i, x) => x.value.trim() !== '').length);
  }).on('keydown', '.reading-in', function (e) { if (e.key === 'Enter') { e.preventDefault(); const $n = $('.reading-in').eq($('.reading-in').index(this) + 1); $n.length ? $n.trigger('focus') : $('#bulk-form').trigger('submit'); } });
  $('#bulk-form').on('vk:saved', (e, r) => { if (r.errors && Object.keys(r.errors).length) { VK.showErrors($('#bulk-form'), r.errors); $('.reading-in').not('.is-invalid').val('').trigger('input'); } else setTimeout(() => location.reload(), 600); });
  </script>
  <?php page_script(ob_get_clean());

elseif ($tab === 'pending'):
    $rows = all("SELECT r.*, m.serial_no, u.unit_no, pr.name AS property, bt.name AS bt_name, bt.unit_label, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS reader
        FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id JOIN prpt_properties pr ON pr.id = m.property_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id LEFT JOIN prpt_units u ON u.id = r.unit_id LEFT JOIN sys_users su ON su.id = r.read_by
        WHERE r.status = 'pending' AND " . scope_sql('m.property_id') . " ORDER BY r.reading_date DESC, r.id DESC LIMIT 300"); ?>
  <div class="vk-panel overflow-hidden">
    <div class="flex flex-wrap items-center gap-2 px-5 py-3 border-b border-line">
      <span class="text-sm text-muted"><b id="sel-count">0</b> selected</span>
      <?php if (can('property.readings.approve')): ?>
      <button class="vk-btn-dark !py-2 text-xs" id="approve-sel"><i class="fa-solid fa-check"></i>Approve selected</button>
      <button class="vk-btn-ghost !py-2 text-xs !text-rose-600" id="reject-sel"><i class="fa-solid fa-xmark"></i>Reject selected</button>
      <?php endif; ?>
    </div>
    <div class="overflow-x-auto"><table class="vk-table">
      <thead><tr><th class="w-8"><input type="checkbox" id="sel-all" class="accent-amber-600"></th><th>Unit / meter</th><th>Date</th><th class="num">Prev → current</th><th class="num">Consumption</th><th>Flag</th><th>Photo</th><th>Reader</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><input type="checkbox" class="row-sel accent-amber-600" value="<?= $r['id'] ?>"></td>
          <td><b><?= e($r['unit_no'] ?? 'Common') ?></b> · <?= e($r['bt_name']) ?><span class="block text-xs text-muted"><?= e($r['property']) ?> · <?= e($r['serial_no']) ?></span></td>
          <td class="text-sm"><?= fdate($r['reading_date']) ?><span class="block text-xs text-muted"><?= e($r['source']) ?></span></td>
          <td class="num text-sm"><?= num($r['previous_reading'], 2) ?> → <b><?= num($r['current_reading'], 2) ?></b></td>
          <td class="num font-bold"><?= num($r['consumption'], 2) ?> <span class="text-xs text-muted"><?= e($r['unit_label']) ?></span></td>
          <td><?= $r['anomaly_flag'] ? pill('overdue', $r['anomaly_flag'], 'amber') : '—' ?></td>
          <td><?= $r['photo_path'] ? '<a href="' . e(file_url($r['photo_path'])) . '" target="_blank"><img src="' . e(file_url($r['photo_path'])) . '" class="h-10 w-14 rounded-lg object-cover border border-line" alt="meter"></a>' : '<span class="text-xs text-muted">none</span>' ?></td>
          <td class="text-xs"><?= e($r['reader']) ?><span class="block text-muted"><?= time_ago($r['created_at']) ?></span></td>
          <td class="text-right whitespace-nowrap"><?php if (can('property.readings.approve')): ?>
            <button class="vk-icon-btn !h-8 !w-8" title="Approve" data-action="<?= e(url('api.php', ['r' => 'property/reading-review'])) ?>" data-payload='{"id":<?= $r['id'] ?>,"decision":"approve"}'><i class="fa-solid fa-check text-emerald-600"></i></button>
            <button class="vk-icon-btn !h-8 !w-8" title="Correct the value" data-action="<?= e(url('api.php', ['r' => 'property/reading-review'])) ?>" data-payload='{"id":<?= $r['id'] ?>,"decision":"correct"}' data-prompt="Corrected reading" data-prompt-type="number" data-prompt-name="corrected" data-prompt-value="<?= (float)$r['current_reading'] ?>"><i class="fa-solid fa-pen text-xs"></i></button>
            <button class="vk-icon-btn !h-8 !w-8" title="Reject" data-action="<?= e(url('api.php', ['r' => 'property/reading-review'])) ?>" data-payload='{"id":<?= $r['id'] ?>,"decision":"reject"}' data-confirm="Reject this reading? It will not be billed."><i class="fa-solid fa-xmark text-rose-600"></i></button>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; if (!$rows) echo '<tr><td colspan="9">' . ui_empty('fa-circle-check', 'Nothing waiting for approval') . '</td></tr>'; ?>
      </tbody></table></div>
  </div>
  <?php ob_start(); ?>
  <script>
  $('#sel-all').on('change', function () { $('.row-sel').prop('checked', this.checked); $('#sel-count').text($('.row-sel:checked').length); });
  $(document).on('change', '.row-sel', () => $('#sel-count').text($('.row-sel:checked').length));
  function review(decision) { const ids = $('.row-sel:checked').map((i, x) => x.value).get(); if (!ids.length) return VK.toast('Select readings first', 'warning');
    VK.api('property/reading-review', { ids, decision }).then(r => { VK.toast(r.message); setTimeout(() => location.reload(), 400); }); }
  $('#approve-sel').on('click', () => review('approve')); $('#reject-sel').on('click', () => VK.confirm({ title: 'Reject the selected readings?' }).then(ok => ok && review('reject')));
  </script>
  <?php page_script(ob_get_clean());

else:
    $f = ['unit_id' => (int)($_GET['unit_id'] ?? 0), 'from' => $_GET['from'] ?? date('Y-m-01', strtotime('-5 months')), 'to' => $_GET['to'] ?? date('Y-m-d'), 'assignment' => (int)($_GET['assignment'] ?? 0)];
    $w = ['r.reading_date BETWEEN :f AND :t', scope_sql('m.property_id')]; $p = ['f' => $f['from'], 't' => $f['to']];
    if ($pid) { $w[] = 'm.property_id = :p'; $p['p'] = $pid; }
    if ($btId) { $w[] = 'm.bill_type_id = :bt'; $p['bt'] = $btId; }
    if ($f['unit_id']) { $w[] = 'r.unit_id = :u'; $p['u'] = $f['unit_id']; }
    if ($f['assignment']) { $w[] = 'r.assignment_id = :a'; $p['a'] = $f['assignment']; }
    $where = implode(' AND ', $w);
    $total = (int)val("SELECT COUNT(*) FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id WHERE $where", $p);
    [$lim, $off, $pager] = ui_paginate($total, 50);
    $rows = all("SELECT r.*, m.serial_no, u.unit_no, bt.name AS bt_name, bt.unit_label FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id LEFT JOIN prpt_units u ON u.id = r.unit_id WHERE $where ORDER BY r.reading_date DESC, r.id DESC LIMIT $lim OFFSET $off", $p);
    $trend = all("SELECT DATE_FORMAT(r.reading_date, '%Y-%m') ym, bt.name, SUM(r.consumption) c FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id WHERE $where AND r.status <> 'rejected' GROUP BY ym, bt.name ORDER BY ym", $p); ?>
  <?= ui_filter_open() ?>
    <input type="hidden" name="tab" value="history">
    <?= ui_field('Property', ui_select('property_id', ui_options($props, 'id', 'name', $pid, 'All')), 'w-52') ?>
    <?= ui_field('Utility', ui_select('bill_type_id', ui_options($metered, 'id', 'name', $btId, 'All')), 'w-36') ?>
    <?= ui_field('Unit', ui_unit_picker('unit_id', $f['unit_id'] ?: null), 'w-52') ?>
    <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
  <?= ui_filter_close() ?>
  <?php if ($trend): ?><div class="vk-panel p-5 mb-5"><h3 class="font-bold mb-3">Consumption trend</h3><div class="h-56"><canvas id="c-trend"></canvas></div></div><?php endif; ?>
  <div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
    <thead><tr><th>Date</th><th>Unit</th><th>Utility</th><th>Meter</th><th class="num">Previous</th><th class="num">Current</th><th class="num">Consumption</th><th>Source</th><th>Status</th><th>Photo</th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?><tr><td><?= fdate($r['reading_date']) ?></td><td><b><?= e($r['unit_no'] ?? 'Common') ?></b></td><td><?= e($r['bt_name']) ?></td><td class="font-mono text-xs"><?= e($r['serial_no']) ?></td>
      <td class="num"><?= num($r['previous_reading'], 2) ?></td><td class="num"><?= num($r['current_reading'], 2) ?></td><td class="num font-bold"><?= num($r['consumption'], 2) ?> <span class="text-xs text-muted"><?= e($r['unit_label']) ?></span></td>
      <td class="text-xs"><?= e($r['source']) ?></td><td><?= pill($r['status']) ?><?= $r['anomaly_flag'] ? '<span class="block text-[11px] text-amber-600">' . e($r['anomaly_flag']) . '</span>' : '' ?></td>
      <td><?= $r['photo_path'] ? '<a class="text-accent" target="_blank" href="' . e(file_url($r['photo_path'])) . '"><i class="fa-solid fa-camera"></i></a>' : '' ?></td></tr><?php endforeach; ?>
    <?php if (!$rows) echo '<tr><td colspan="10">' . ui_empty('fa-gauge', 'No readings in this range') . '</td></tr>'; ?>
    </tbody></table></div><?= $pager ?></div>
  <?php if ($trend) { $months = array_values(array_unique(array_column($trend, 'ym'))); $series = [];
    foreach ($trend as $t) $series[$t['name']][$t['ym']] = (float)$t['c'];
    $colors = ['#0EA5E9', '#F59E0B', '#10B981', '#8B5CF6', '#EF4444']; $ds = []; $i = 0;
    foreach ($series as $n => $vals) { $ds[] = ['label' => $n, 'data' => array_map(fn($m) => $vals[$m] ?? 0, $months), 'backgroundColor' => $colors[$i++ % 5], 'borderRadius' => 6]; }
    page_script('<script>VK.chart("c-trend",{type:"bar",data:{labels:' . json_encode(array_map('month_label', $months)) . ',datasets:' . json_encode($ds) . '},options:{maintainAspectRatio:false,plugins:{legend:{position:"bottom"}}}})</script>'); }
endif;
