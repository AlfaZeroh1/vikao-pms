<?php
$title = 'Properties';
$rows = all("SELECT p.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS manager,
    (SELECT COUNT(*) FROM prpt_units x WHERE x.property_id = p.id AND x.deleted_at IS NULL) units,
    (SELECT COUNT(*) FROM prpt_units x WHERE x.property_id = p.id AND x.deleted_at IS NULL AND x.status IN ('occupied','owner_occupied')) occ,
    (SELECT COUNT(*) FROM prpt_blocks b WHERE b.property_id = p.id) blocks,
    (SELECT COUNT(DISTINCT o.party_id) FROM prpt_ownerships o WHERE o.property_id = p.id AND (o.end_date IS NULL OR o.end_date >= CURDATE())) owners,
    (SELECT COALESCE(SUM(i.balance),0) FROM bln_invoices i WHERE i.property_id = p.id AND i.status IN ('issued','partially_paid','overdue') AND i.due_date < CURDATE()) arrears,
    (SELECT COALESCE(SUM(pm.amount),0) FROM fin_payments pm WHERE pm.property_id = p.id AND pm.status <> 'reversed' AND pm.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) collected
    FROM prpt_properties p LEFT JOIN sys_users u ON u.id = p.manager_user_id WHERE p.deleted_at IS NULL AND " . scope_sql('p.id') . " ORDER BY p.name");
if (can('property.properties.manage')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-prop"><i class="fa-solid fa-plus"></i>New property</button>';
$typeIcon = ['residential' => 'fa-house-chimney', 'commercial' => 'fa-building', 'mixed_use' => 'fa-city', 'land' => 'fa-mountain-sun', 'short_stay' => 'fa-bed'];
?>
<?php if (!$rows): echo '<div class="vk-panel">' . ui_empty('fa-building', 'No properties yet', 'Register your first property, then generate its blocks, floors and units in one click.', can('property.properties.manage') ? '<button class="vk-btn-accent" data-modal-open="#m-prop"><i class="fa-solid fa-plus"></i>New property</button>' : '') . '</div>'; endif; ?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-5">
  <?php foreach ($rows as $p): $occ = $p['units'] ? round($p['occ'] / $p['units'] * 100) : 0; ?>
    <a href="<?= e(page('property/property', ['id' => $p['id']])) ?>" class="vk-panel overflow-hidden hover:-translate-y-0.5 hover:shadow-lift transition group">
      <div class="h-28 vk-hero relative p-5 text-white">
        <div class="absolute inset-0 vk-grid-bg opacity-50"></div>
        <div class="relative flex items-start gap-3">
          <span class="grid h-12 w-12 place-items-center rounded-2xl bg-white/10 text-amber-400 text-xl"><i class="fa-solid <?= $typeIcon[$p['property_type']] ?? 'fa-building' ?>"></i></span>
          <div class="min-w-0"><p class="font-display text-lg font-bold truncate"><?= e($p['name']) ?></p><p class="text-xs text-slate-300"><?= e($p['code']) ?> · <?= e(trim(($p['area'] ?? '') . ', ' . ($p['county'] ?? ''), ', ')) ?></p></div>
          <span class="ml-auto"><?= pill($p['status']) ?></span>
        </div>
      </div>
      <div class="p-5">
        <div class="grid grid-cols-4 gap-2 text-center">
          <div><p class="text-lg font-extrabold"><?= $p['blocks'] ?: '—' ?></p><p class="text-[10px] uppercase font-bold text-muted">Blocks</p></div>
          <div><p class="text-lg font-extrabold"><?= $p['units'] ?></p><p class="text-[10px] uppercase font-bold text-muted">Units</p></div>
          <div><p class="text-lg font-extrabold"><?= $p['owners'] ?></p><p class="text-[10px] uppercase font-bold text-muted">Owners</p></div>
          <div><p class="text-lg font-extrabold"><?= $occ ?>%</p><p class="text-[10px] uppercase font-bold text-muted">Occupied</p></div>
        </div>
        <div class="vk-kpi-bar mt-4"><span style="width:<?= $occ ?>%"></span></div>
        <div class="mt-4 flex items-center justify-between text-xs">
          <span class="text-muted"><i class="fa-solid fa-money-bill-trend-up text-emerald-500"></i> <?= money($p['collected'], true, true) ?> this month</span>
          <span class="<?= $p['arrears'] > 0 ? 'text-rose-600 font-bold' : 'text-muted' ?>"><i class="fa-solid fa-triangle-exclamation"></i> <?= money($p['arrears'], true, true) ?></span>
        </div>
        <p class="mt-2 text-xs text-muted"><i class="fa-solid fa-user-tie"></i> <?= e(trim($p['manager'] ?? '') ?: 'No manager assigned') ?> · <?= e(ucwords(str_replace('_', ' ', $p['management_type']))) ?></p>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php
$body = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
  . ui_field('Property name *', ui_input('name', '', 'text', 'required')) . ui_field('Code * <span class="text-muted font-normal">(short, e.g. KRV)</span>', ui_input('code', '', 'text', 'maxlength="20" required'))
  . ui_field('Type', ui_select('property_type', ui_kv_options(['residential' => 'Residential', 'commercial' => 'Commercial', 'mixed_use' => 'Mixed use', 'land' => 'Land', 'short_stay' => 'Short stay'])))
  . ui_field('What we manage', ui_select('management_type', ui_kv_options(['full' => 'Full management', 'rent_collection' => 'Rent collection only', 'service_charge_only' => 'Service charge only (owners\' association)', 'sales_only' => 'Sales only', 'short_stay' => 'Short-stay operations'])))
  . ui_field('County', ui_input('county', 'Nairobi')) . ui_field('Area / estate', ui_input('area')) . '<div class="sm:col-span-2">' . ui_field('Street address', ui_input('address')) . '</div>'
  . ui_field('LR / title number', ui_input('lr_number')) . ui_field('Year built', ui_input('year_built', '', 'number'))
  . ui_field('Auto-invoice on day', ui_input('invoice_day', 1, 'number', 'min="1" max="28"')) . ui_field('Bills due on day', ui_input('due_day', 5, 'number', 'min="1" max="28"'))
  . ui_field('Property manager', ui_select('manager_user_id', ui_options(staff_for_select(), 'id', 'name', null, '—'))) . ui_field('Caretaker name', ui_input('caretaker_name'))
  . ui_field('Caretaker phone', ui_input('caretaker_phone')) . ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'onboarding' => 'Onboarding', 'inactive' => 'Inactive'])))
  . '</div>';
if (can('property.properties.manage')) echo ui_modal('m-prop', 'New property', $body, url('api.php', ['r' => 'property/save-property']), 'Create property', 'max-w-3xl', 'fa-building');
