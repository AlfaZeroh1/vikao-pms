<?php
require_once __DIR__ . '/_partials.php';
$title = 'Units';
$f = ['property_id' => (int)($_GET['property_id'] ?? 0), 'status' => (string)($_GET['status'] ?? ''), 'purpose' => (string)($_GET['purpose'] ?? ''), 'usage' => (string)($_GET['usage'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$w = ['u.deleted_at IS NULL', scope_sql('u.property_id')]; $p = [];
if ($f['property_id']) { $w[] = 'u.property_id = :pid'; $p['pid'] = $f['property_id']; }
if ($f['status']) { $w[] = 'u.status = :st'; $p['st'] = $f['status']; }
if ($f['purpose']) { $w[] = 'u.purpose = :pu'; $p['pu'] = $f['purpose']; }
if ($f['usage']) { $w[] = 'u.usage_class = :us'; $p['us'] = $f['usage']; }
if ($f['q'] !== '') { $w[] = '(u.unit_no LIKE :q OR pr.name LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
$where = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE $where", $p);
[$lim, $off, $pager] = ui_paginate($total, 50);
$rows = all("SELECT u.*, pr.name AS property, b.code AS block_code, f.name AS floor_name,
    (SELECT GROUP_CONCAT(DISTINCT pt.display_name SEPARATOR ', ') FROM bln_leases l JOIN bln_lease_parties lp ON lp.lease_id = l.id AND lp.role IN ('tenant','co_tenant') JOIN prpt_parties pt ON pt.id = lp.party_id WHERE l.unit_id = u.id AND l.status IN ('active','notice')) AS tenants,
    (SELECT COALESCE(SUM(i.balance),0) FROM bln_invoices i WHERE i.unit_id = u.id AND i.status IN ('issued','partially_paid','overdue') AND i.due_date < CURDATE()) AS arrears
    FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id LEFT JOIN prpt_blocks b ON b.id = u.block_id LEFT JOIN prpt_floors f ON f.id = u.floor_id
    WHERE $where ORDER BY pr.name, b.code, u.unit_no LIMIT $lim OFFSET $off", $p);
$counts = []; foreach (all("SELECT status, COUNT(*) n FROM prpt_units u WHERE u.deleted_at IS NULL AND " . scope_sql('u.property_id') . ($f['property_id'] ? ' AND u.property_id = ' . $f['property_id'] : '') . " GROUP BY status") as $r) $counts[$r['status']] = (int)$r['n'];
$statuses = ['vacant' => 'Vacant', 'occupied' => 'Occupied', 'owner_occupied' => 'Owner occupied', 'reserved' => 'Reserved', 'sold' => 'Sold', 'under_maintenance' => 'Maintenance', 'unavailable' => 'Unavailable'];
if (can('property.properties.manage')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-unit"><i class="fa-solid fa-plus"></i>Add unit</button>';
?>
<div class="flex flex-wrap gap-2 mb-4">
  <?php foreach ($statuses as $k => $l): ?><a href="<?= e(page('property/units', array_filter(['status' => $k, 'property_id' => $f['property_id'] ?: null]))) ?>" class="vk-chip <?= $f['status'] === $k ? '!border-accent !bg-accent/10' : '' ?>"><?= $l ?> <b><?= $counts[$k] ?? 0 ?></b></a><?php endforeach; ?>
</div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Unit no. or property"'), 'w-56') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All properties')), 'w-56') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options($statuses, $f['status'], 'Any')), 'w-40') ?>
  <?= ui_field('Purpose', ui_select('purpose', ui_kv_options(['rent' => 'Rent', 'sale' => 'Sale', 'rent_and_sale' => 'Rent or sale', 'owner_occupied' => 'Owner occupied', 'short_stay' => 'Short stay'], $f['purpose'], 'Any')), 'w-40') ?>
  <?= ui_field('Usage', ui_select('usage', ui_kv_options(['residential' => 'Residential', 'commercial' => 'Commercial'], $f['usage'], 'Any')), 'w-36') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden">
  <?php if (can('property.properties.manage')): ?>
  <div class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-b border-line text-sm bg-surface2/40" id="bulk-bar">
    <span class="text-muted"><b id="sel-count">0</b> selected</span>
    <select id="bulk-status" class="vk-select !py-1.5 !w-48"><?= ui_kv_options($statuses, null, 'Set status…') ?></select>
    <button class="vk-btn-dark !py-1.5 text-xs" id="bulk-apply">Apply</button>
  </div>
  <?php endif; ?>
  <div class="overflow-x-auto"><table class="vk-table">
    <thead><tr><th class="w-8"><input type="checkbox" id="sel-all" class="accent-amber-600"></th><th>Unit</th><th>Property</th><th>Type</th><th>Purpose</th><th>Status</th><th>Occupant</th><th class="num">Rent</th><th class="num">Service ch.</th><th class="num">Arrears</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $u): ?>
      <tr data-href="<?= e(page('property/unit', ['id' => $u['id']])) ?>">
        <td><input type="checkbox" class="row-sel accent-amber-600" value="<?= $u['id'] ?>"></td>
        <td><b><?= e($u['unit_no']) ?></b><span class="block text-xs text-muted"><?= e(trim(($u['block_code'] ? 'Block ' . $u['block_code'] : '') . ' ' . ($u['floor_name'] ?? ''))) ?></span></td>
        <td><?= e($u['property']) ?></td>
        <td><?= e(ucfirst($u['unit_type'])) ?><span class="block text-xs text-muted"><?= $u['bedrooms'] !== null ? $u['bedrooms'] . ' bd · ' : '' ?><?= e($u['usage_class']) ?></span></td>
        <td class="text-xs"><?= e(ucwords(str_replace('_', ' ', $u['purpose']))) ?></td>
        <td><?= pill($u['status']) ?></td>
        <td class="text-sm"><?= e($u['tenants'] ?: '—') ?></td>
        <td class="num"><?= $u['rent_amount'] ? money($u['rent_amount'], false) : '—' ?></td>
        <td class="num"><?= $u['service_charge'] ? money($u['service_charge'], false) : '—' ?></td>
        <td class="num"><?= ui_money_cell($u['arrears'], true) ?></td>
      </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="10">' . ui_empty('fa-door-open', 'No units match') . '</td></tr>'; ?>
    </tbody></table></div>
  <?= $pager ?>
</div>
<?= unit_modal_html($f['property_id'] ?: null) ?>
<?php ob_start(); ?>
<script>
$('#sel-all').on('change', function () { $('.row-sel').prop('checked', this.checked).trigger('change'); });
$(document).on('change', '.row-sel', () => $('#sel-count').text($('.row-sel:checked').length));
$('#bulk-apply').on('click', function () {
  const ids = $('.row-sel:checked').map((i, x) => x.value).get(), st = $('#bulk-status').val();
  if (!ids.length || !st) return VK.toast('Select units and a status', 'warning');
  VK.api('property/unit-status', { ids, status: st, reason: 'Bulk update' }).then(r => { VK.toast(r.message); setTimeout(() => location.reload(), 400); });
});
</script>
<?php page_script(ob_get_clean()); ?>
