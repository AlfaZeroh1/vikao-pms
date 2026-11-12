<?php
require_once __DIR__ . '/_partials.php';
$id = (int)($_GET['id'] ?? 0);
$p = one("SELECT * FROM prpt_properties WHERE id = ? AND deleted_at IS NULL AND " . scope_sql('id'), [$id]);
if (!$p) { echo ui_empty('fa-building', 'Property not found'); return; }
$title = $p['name']; $subtitle = e($p['code']) . ' · ' . e(trim(($p['address'] ?? '') . ' ' . ($p['area'] ?? '') . ', ' . ($p['county'] ?? ''), ' ,')) . ' · ' . pill($p['status']);
$crumbs = [['Properties', page('property/properties')]];
$tab = $_GET['tab'] ?? 'overview';
$canEdit = can('property.properties.manage');
$stats = one("SELECT COUNT(*) units, SUM(status IN ('occupied','owner_occupied')) occ, SUM(status = 'vacant') vac, SUM(status = 'reserved') res, SUM(status = 'sold') sold,
              COALESCE(SUM(rent_amount),0) potential FROM prpt_units WHERE property_id = ? AND deleted_at IS NULL", [$id]);
$tabs = ['overview' => ['Overview', 'fa-gauge'], 'structure' => ['Blocks & units', 'fa-sitemap', (int)$stats['units']], 'owners' => ['Owners & agreements', 'fa-key'],
    'charges' => ['Charges & collection', 'fa-receipt'], 'meters' => ['Meters', 'fa-gauge'], 'amenities' => ['Amenities & parking', 'fa-person-swimming'], 'notices' => ['Rules & notices', 'fa-bullhorn'], 'documents' => ['Documents', 'fa-folder-open']];
if ($canEdit) $actions = '<button class="vk-btn-ghost" data-modal-open="#m-prop-edit"><i class="fa-solid fa-pen"></i>Edit</button><a class="vk-btn-ghost" href="' . e(page('finance/statement', ['type' => 'property', 'id' => $id])) . '"><i class="fa-solid fa-file-lines"></i>Statement</a><button class="vk-btn-accent" data-modal-open="#m-unit"><i class="fa-solid fa-plus"></i>Add unit</button>';
echo ui_tabs($tabs, $tab, 'tab', ['id' => $id]);

if ($tab === 'overview'):
    $m0 = date('Y-m-01');
    $fin = one("SELECT (SELECT COALESCE(SUM(total),0) FROM bln_invoices WHERE property_id = :p AND status NOT IN ('void','draft') AND issue_date >= :m) inv,
        (SELECT COALESCE(SUM(amount),0) FROM fin_payments WHERE property_id = :p AND status <> 'reversed' AND paid_at >= :m) col,
        (SELECT COALESCE(SUM(balance),0) FROM bln_invoices WHERE property_id = :p AND status IN ('issued','partially_paid','overdue') AND due_date < CURDATE()) arr,
        (SELECT COALESCE(SUM(amount),0) FROM fin_expenses WHERE property_id = :p AND status <> 'rejected' AND expense_date >= :m) exp,
        (SELECT COUNT(*) FROM crm_tickets WHERE property_id = :p AND status NOT IN ('resolved','closed','cancelled')) tickets,
        (SELECT COUNT(*) FROM bln_leases WHERE property_id = :p AND status IN ('active','notice')) leases", ['p' => $id, 'm' => $m0]);
    $occ = $stats['units'] ? round($stats['occ'] / $stats['units'] * 100) : 0;
    $mgr = $p['manager_user_id'] ? staff_name(one("SELECT * FROM sys_users WHERE id = ?", [$p['manager_user_id']])) : '—';
    $owners = all("SELECT DISTINCT pt.id, pt.display_name, o.scope FROM prpt_ownerships o JOIN prpt_parties pt ON pt.id = o.party_id WHERE o.property_id = ? AND (o.end_date IS NULL OR o.end_date >= CURDATE()) LIMIT 8", [$id]);
?>
  <div class="grid grid-cols-2 lg:grid-cols-4 xl:grid-cols-6 gap-3 mb-6">
    <?= ui_stat('fa-door-open', 'Units', num($stats['units']), 'blue', $occ . '% occupied') ?>
    <?= ui_stat('fa-house-circle-xmark', 'Vacant', num($stats['vac']), $stats['vac'] ? 'accent' : 'slate', $stats['res'] . ' reserved') ?>
    <?= ui_stat('fa-file-invoice', 'Invoiced this month', money($fin['inv'], true, true), 'accent') ?>
    <?= ui_stat('fa-money-bill-trend-up', 'Collected this month', money($fin['col'], true, true), 'green') ?>
    <?= ui_stat('fa-triangle-exclamation', 'Overdue', money($fin['arr'], true, true), 'red', null, page('billing/arrears', ['property_id' => $id])) ?>
    <?= ui_stat('fa-ticket', 'Open tickets', num($fin['tickets']), 'violet', null, page('crm/tickets', ['property_id' => $id])) ?>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="vk-panel p-5 lg:col-span-2">
      <h3 class="font-bold mb-4">Details</h3>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-3 text-sm">
        <?php foreach ([['Type', ucwords(str_replace('_', ' ', $p['property_type']))], ['Management', ucwords(str_replace('_', ' ', $p['management_type']))], ['LR / title', $p['lr_number'] ?: '—'], ['Year built', $p['year_built'] ?: '—'],
                        ['Property manager', $mgr], ['Caretaker', trim(($p['caretaker_name'] ?? '') . ' ' . ($p['caretaker_phone'] ?? '')) ?: '—'], ['Auto-invoice day', ordinal((int)$p['invoice_day'])], ['Bills due', ordinal((int)$p['due_day']) . ' of the month'],
                        ['Active accounts', $fin['leases']], ['Expenses this month', money($fin['exp'])], ['Rent potential (full occupancy)', money($stats['potential'])], ['Sold units', $stats['sold']]] as [$k, $v]): ?>
          <div class="flex justify-between gap-3 border-b border-line/60 pb-2"><dt class="text-muted"><?= e($k) ?></dt><dd class="font-semibold text-right"><?= e((string)$v) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <?php if ($p['description']): ?><p class="mt-4 text-sm text-inksoft"><?= nl2br(e($p['description'])) ?></p><?php endif; ?>
    </div>
    <div class="vk-panel p-5">
      <h3 class="font-bold mb-3">Owners</h3>
      <ul class="space-y-2"><?php foreach ($owners as $o): ?><li><a class="flex items-center gap-2 hover:text-accent" href="<?= e(page('property/party', ['id' => $o['id']])) ?>"><?= ui_avatar($o['display_name'], 'h-8 w-8') ?><span class="text-sm font-semibold"><?= e($o['display_name']) ?></span><span class="ml-auto text-xs text-muted"><?= e($o['scope']) ?></span></a></li><?php endforeach; ?></ul>
      <?php if (!$owners) echo '<p class="text-sm text-muted">No owners recorded yet.</p>'; ?>
      <a href="<?= e(page('property/property', ['id' => $id, 'tab' => 'owners'])) ?>" class="mt-4 inline-block text-xs font-bold text-accent">Manage ownership →</a>
    </div>
  </div>

<?php elseif ($tab === 'structure'):
    $units = all("SELECT u.*, b.code AS block_code, b.name AS block_name, f.name AS floor_name, f.level,
        (SELECT pt.display_name FROM bln_leases l JOIN bln_lease_parties lp ON lp.lease_id = l.id AND lp.role IN ('tenant','co_tenant') JOIN prpt_parties pt ON pt.id = lp.party_id WHERE l.unit_id = u.id AND l.status IN ('active','notice') ORDER BY lp.is_primary DESC LIMIT 1) AS tenant,
        (SELECT COALESCE(SUM(i.balance),0) FROM bln_invoices i WHERE i.unit_id = u.id AND i.status IN ('issued','partially_paid','overdue') AND i.due_date < CURDATE()) AS arrears
        FROM prpt_units u LEFT JOIN prpt_blocks b ON b.id = u.block_id LEFT JOIN prpt_floors f ON f.id = u.floor_id WHERE u.property_id = ? AND u.deleted_at IS NULL ORDER BY b.sort_order, b.code, f.level DESC, u.unit_no", [$id]);
    $grouped = []; foreach ($units as $u) $grouped[$u['block_code'] ? 'Block ' . $u['block_code'] : 'Units'][$u['floor_name'] ?? '—'][] = $u;
    $tone = ['vacant' => 'border-amber-400 bg-amber-50 dark:bg-amber-500/10', 'occupied' => 'border-emerald-400 bg-emerald-50 dark:bg-emerald-500/10', 'owner_occupied' => 'border-violet-400 bg-violet-50 dark:bg-violet-500/10',
        'reserved' => 'border-sky-400 bg-sky-50 dark:bg-sky-500/10', 'sold' => 'border-indigo-400 bg-indigo-50 dark:bg-indigo-500/10', 'under_maintenance' => 'border-rose-400 bg-rose-50 dark:bg-rose-500/10', 'unavailable' => 'border-slate-300 bg-slate-50 dark:bg-slate-500/10'];
?>
  <div class="flex flex-wrap items-center gap-3 mb-4 text-xs font-semibold">
    <?php foreach (['vacant' => 'Vacant', 'occupied' => 'Occupied', 'owner_occupied' => 'Owner occupied', 'reserved' => 'Reserved', 'sold' => 'Sold', 'under_maintenance' => 'Maintenance'] as $s => $l): ?><span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded border-2 <?= $tone[$s] ?>"></span><?= $l ?></span><?php endforeach; ?>
    <?php if ($canEdit): ?><button class="ml-auto vk-btn-dark !py-2" data-modal-open="#m-gen"><i class="fa-solid fa-wand-magic-sparkles"></i>Generate blocks, floors & units</button><?php endif; ?>
  </div>
  <?php if (!$units) echo '<div class="vk-panel">' . ui_empty('fa-sitemap', 'No units yet', 'Generate the whole structure in one go — e.g. 2 blocks × 5 floors × 4 units.') . '</div>'; ?>
  <div class="space-y-6">
  <?php foreach ($grouped as $block => $floors): ?>
    <div class="vk-panel p-5">
      <h3 class="font-display font-bold text-lg mb-4"><i class="fa-solid fa-building text-accent mr-2"></i><?= e($block) ?></h3>
      <div class="space-y-3">
      <?php foreach ($floors as $floor => $list): ?>
        <div class="flex gap-3 items-start"><span class="w-28 shrink-0 pt-3 text-xs font-bold uppercase tracking-wide text-muted"><?= e($floor) ?></span>
          <div class="flex flex-wrap gap-2 flex-1">
            <?php foreach ($list as $u): ?>
              <a href="<?= e(page('property/unit', ['id' => $u['id']])) ?>" class="w-36 rounded-xl border-2 p-2.5 hover:shadow-soft transition <?= $tone[$u['status']] ?? $tone['unavailable'] ?>">
                <div class="flex items-center gap-1"><b class="text-sm"><?= e($u['unit_no']) ?></b><?php if ($u['arrears'] > 0): ?><i class="fa-solid fa-circle-exclamation text-rose-500 text-xs ml-auto" title="<?= e(money($u['arrears'])) ?> overdue"></i><?php endif; ?></div>
                <p class="text-[11px] text-muted truncate"><?= e($u['tenant'] ?: ucwords(str_replace('_', ' ', $u['status']))) ?></p>
                <p class="text-[11px] font-semibold"><?= $u['rent_amount'] ? money($u['rent_amount'], true, true) : '' ?></p>
              </a>
            <?php endforeach; ?>
          </div></div>
      <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="mt-6"><?= render_crud('blocks', ['property_id' => $id], ['title' => 'Blocks']) ?></div>
<?php
    echo !$canEdit ? '' : ui_modal('m-gen', 'Generate structure', '<input type="hidden" name="property_id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-3 gap-4">'
        . ui_field('Number of blocks (0 = none)', ui_input('blocks', 1, 'number', 'min="0" max="26"')) . ui_field('From floor (0 = ground)', ui_input('floor_from', 0, 'number')) . ui_field('To floor', ui_input('floor_to', 4, 'number'))
        . ui_field('Units per floor', ui_input('units_per_floor', 4, 'number', 'min="1" max="50"')) . ui_field('Unit number pattern', ui_input('pattern', '{block}{floor}{n}'), 'sm:col-span-2')
        . ui_field('Unit type', ui_select('unit_type', ui_kv_options(unit_types(), 'apartment'))) . ui_field('Usage', ui_select('usage_class', ui_kv_options(['residential' => 'Residential', 'commercial' => 'Commercial'])))
        . ui_field('Purpose', ui_select('purpose', ui_kv_options(['rent' => 'For rent', 'sale' => 'For sale', 'rent_and_sale' => 'Rent or sale', 'owner_occupied' => 'Owner occupied', 'short_stay' => 'Short stay'])))
        . ui_field('Bedrooms', ui_input('bedrooms', 2, 'number')) . ui_field('Bathrooms', ui_input('bathrooms', 2, 'number')) . ui_field('Size m²', ui_input('size_sqm', '', 'number'))
        . ui_field('Default rent', ui_input('rent_amount', '', 'number')) . ui_field('Service charge', ui_input('service_charge', '', 'number')) . ui_field('Deposit (months)', ui_input('deposit_months', 1, 'number', 'step="0.5"'))
        . '</div><p class="text-xs text-muted">Pattern tokens: <code>{block}</code> A, B… · <code>{floor}</code> G, 1, 2… · <code>{n}</code> 01, 02… · <code>{nn}</code> 1, 2… — e.g. <code>{block}{floor}{n}</code> → A101, A102. Existing unit numbers are skipped, so you can run it again safely.</p>',
        url('api.php', ['r' => 'property/generate-structure']), 'Generate', 'max-w-3xl', 'fa-wand-magic-sparkles');

elseif ($tab === 'owners'):
    $own = all("SELECT o.*, pt.display_name, pt.phone, u.unit_no, b.code AS block_code FROM prpt_ownerships o JOIN prpt_parties pt ON pt.id = o.party_id LEFT JOIN prpt_units u ON u.id = o.unit_id LEFT JOIN prpt_blocks b ON b.id = o.block_id
                WHERE o.property_id = ? ORDER BY o.end_date IS NOT NULL, FIELD(o.scope,'property','block','unit'), b.code, u.unit_no", [$id]);
?>
  <div class="vk-panel overflow-hidden mb-6">
    <div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold"><i class="fa-solid fa-key text-accent mr-2"></i>Ownership register</h3>
      <?php if (can('property.ownership.manage')): ?><button class="ml-auto vk-btn-accent !py-2 text-sm" data-modal-open="#m-own"><i class="fa-solid fa-plus"></i>Add owner</button><?php endif; ?></div>
    <table class="vk-table"><thead><tr><th>Owner</th><th>Owns</th><th class="num">Share</th><th>Since</th><th>Acquired</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($own as $o): $ended = $o['end_date'] && $o['end_date'] < date('Y-m-d'); ?>
        <tr class="<?= $ended ? 'opacity-50' : '' ?>"><td><a class="font-semibold hover:text-accent" href="<?= e(page('property/party', ['id' => $o['party_id']])) ?>"><?= e($o['display_name']) ?></a><span class="block text-xs text-muted"><?= e($o['phone']) ?></span></td>
          <td><?= $o['scope'] === 'unit' ? '<i class="fa-solid fa-door-open text-muted"></i> Unit ' . e($o['unit_no']) : ($o['scope'] === 'block' ? '<i class="fa-solid fa-building text-muted"></i> Block ' . e($o['block_code']) : '<i class="fa-solid fa-city text-muted"></i> Whole property') ?></td>
          <td class="num font-bold"><?= num($o['share_pct'], 2) ?>%</td><td><?= fdate($o['start_date']) ?></td><td><?= e(ucfirst($o['acquisition'])) ?></td><td><?= $ended ? pill('ended', 'Ended ' . fdate($o['end_date'])) : pill('active') ?></td>
          <td class="text-right"><?php if (!$ended && can('property.ownership.manage')): ?><button class="vk-btn-ghost !py-1.5 !px-3 text-xs" data-action="<?= e(url('api.php', ['r' => 'property/end-ownership', 'id' => $o['id']])) ?>" data-prompt="End ownership on (date)" data-prompt-type="date" data-prompt-name="end_date" data-prompt-value="<?= date('Y-m-d') ?>">End</button><?php endif; ?></td></tr>
      <?php endforeach; if (!$own) echo '<tr><td colspan="7">' . ui_empty('fa-key', 'No owners yet', 'Record who owns the whole property, a block, or individual units (with co-owner shares).') . '</td></tr>'; ?>
    </tbody></table>
  </div>
  <?= render_crud('agreements', ['property_id' => $id], ['title' => 'Management & sales agreements', 'empty' => 'Define the fee Vikao deducts from owner collections (% or fixed per unit) and the sales commission.']) ?>
<?php echo can('property.ownership.manage') ? ownership_modal_html($id) : '';

elseif ($tab === 'charges'):
    $accs = all("SELECT fpa.*, a.name, a.account_type, a.number FROM fin_property_accounts fpa JOIN fin_accounts a ON a.id = fpa.account_id WHERE fpa.property_id = ?", [$id]);
    $allAcc = all("SELECT id, name FROM fin_accounts WHERE is_active = 1 ORDER BY is_master DESC, name");
?>
  <div class="vk-alert-info mb-5 text-sm"><i class="fa-solid fa-circle-info text-sky-500"></i> These defaults pre-fill every new billing account in this property (you can still edit per unit). Rent and service charge come from each unit's own figures.</div>
  <?= render_crud('property_charges', ['property_id' => $id], ['title' => 'Default charges for new accounts']) ?>
  <div class="vk-panel overflow-hidden mt-6">
    <div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold"><i class="fa-solid fa-building-columns text-accent mr-2"></i>Where tenants pay (collection accounts)</h3>
      <?php if (can('finance.accounts.manage')): ?><button class="ml-auto vk-btn-accent !py-2 text-sm" data-modal-open="#m-acc"><i class="fa-solid fa-plus"></i>Link account</button><?php endif; ?></div>
    <table class="vk-table"><thead><tr><th>Account</th><th>Type</th><th>Number</th><th>Payer uses account ref</th><th>Default</th><th></th></tr></thead><tbody>
      <?php foreach ($accs as $a): ?><tr><td class="font-semibold"><?= e($a['name']) ?></td><td><?= e(str_replace('_', ' ', $a['account_type'])) ?></td><td><?= e($a['number'] ?: '—') ?></td><td><code><?= e($a['account_reference_format']) ?></code></td><td><?= $a['is_default'] ? '<i class="fa-solid fa-star text-amber-500"></i>' : '' ?></td>
        <td class="text-right"><?php if (can('finance.accounts.manage')): ?><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'property/property-account'])) ?>" data-payload='<?= e(json_encode(['property_id' => $id, 'account_id' => $a['account_id'], 'remove' => 1])) ?>' data-confirm="Unlink this account?"><i class="fa-solid fa-link-slash text-xs"></i></button><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <p class="px-5 py-3 text-xs text-muted border-t border-line">Use the Vikao master accounts when we collect on the owner's behalf, or add the owner's own paybill / bank account under Finance → Collection accounts and link it here.</p>
  </div>
<?php echo !can('finance.accounts.manage') ? '' : ui_modal('m-acc', 'Link collection account', '<input type="hidden" name="property_id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Account', ui_select('account_id', ui_options($allAcc, 'id', 'name'))) . ui_field('Account reference payers type', ui_input('account_reference_format', '{unit_no}')) . '</div><label class="vk-switch"><input type="checkbox" name="is_default" value="1">Default for this property</label>', url('api.php', ['r' => 'property/property-account']), 'Link', 'max-w-xl', 'fa-link');

elseif ($tab === 'meters'): ?>
  <div class="flex gap-2 mb-4"><a class="vk-btn-ghost" href="<?= e(page('property/readings', ['property_id' => $id])) ?>"><i class="fa-solid fa-table"></i>Bulk readings</a><a class="vk-btn-ghost" href="<?= e(page('property/assignments', ['property_id' => $id])) ?>"><i class="fa-solid fa-clipboard-list"></i>Assign a reading exercise</a></div>
  <?= render_crud('meters', ['property_id' => $id], ['title' => 'Meters', 'empty' => 'Add a meter per unit for each metered utility (water, electricity, gas).']) ?>
<?php elseif ($tab === 'amenities'): ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6"><?= render_crud('amenities', ['property_id' => $id], ['title' => 'Amenities']) ?><?= render_crud('parking', ['property_id' => $id], ['title' => 'Parking slots']) ?></div>
<?php elseif ($tab === 'notices'): ?>
  <?= render_crud('notices', ['property_id' => $id], ['title' => 'House rules, schedules & notices', 'empty' => 'Shown to owners and tenants in their self-service portal (e.g. garbage collection days, gym hours, water rationing).']) ?>
<?php elseif ($tab === 'documents'): ?>
  <div class="vk-panel overflow-hidden"><div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold">Property documents</h3><?php if (can('property.documents.manage')): ?><button class="ml-auto vk-btn-accent !py-2 text-sm" data-modal-open="#m-doc"><i class="fa-solid fa-upload"></i>Upload</button><?php endif; ?></div>
    <?= documents_table("d.owner_type = 'property' AND d.owner_id = :id", ['id' => $id]) ?></div>
  <?= document_modal_html('property', $id) ?>
<?php endif;

echo unit_modal_html($id);
if ($canEdit) {
    $body = '<input type="hidden" name="id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
      . ui_field('Name', ui_input('name', $p['name'])) . ui_field('Code', ui_input('code', $p['code']))
      . ui_field('Type', ui_select('property_type', ui_kv_options(['residential' => 'Residential', 'commercial' => 'Commercial', 'mixed_use' => 'Mixed use', 'land' => 'Land', 'short_stay' => 'Short stay'], $p['property_type'])))
      . ui_field('Management', ui_select('management_type', ui_kv_options(['full' => 'Full management', 'rent_collection' => 'Rent collection only', 'service_charge_only' => 'Service charge only', 'sales_only' => 'Sales only', 'short_stay' => 'Short stay'], $p['management_type'])))
      . ui_field('County', ui_input('county', $p['county'])) . ui_field('Area', ui_input('area', $p['area'])) . '<div class="sm:col-span-2">' . ui_field('Address', ui_input('address', $p['address'])) . '</div>'
      . ui_field('LR / title', ui_input('lr_number', $p['lr_number'])) . ui_field('Year built', ui_input('year_built', $p['year_built'], 'number'))
      . ui_field('Auto-invoice day', ui_input('invoice_day', $p['invoice_day'], 'number')) . ui_field('Due day', ui_input('due_day', $p['due_day'], 'number'))
      . ui_field('Manager', ui_select('manager_user_id', ui_options(staff_for_select(), 'id', 'name', $p['manager_user_id'], '—'))) . ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'onboarding' => 'Onboarding', 'inactive' => 'Inactive'], $p['status'])))
      . ui_field('Caretaker', ui_input('caretaker_name', $p['caretaker_name'])) . ui_field('Caretaker phone', ui_input('caretaker_phone', $p['caretaker_phone']))
      . ui_field('Marketplace location id', ui_input('ecom_location_id', $p['ecom_location_id'], 'number')) . '<div class="sm:col-span-2">' . ui_field('Description', '<textarea name="description" class="vk-textarea" rows="3">' . e($p['description']) . '</textarea>') . '</div></div>';
    echo ui_modal('m-prop-edit', 'Edit property', $body, url('api.php', ['r' => 'property/save-property']), 'Save', 'max-w-3xl', 'fa-building');
}
