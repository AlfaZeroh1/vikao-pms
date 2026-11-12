<?php
$title = 'Accounts & leases';
$subtitle = 'Every billing account — tenants we manage, owner-occupied homes, owner-managed lets and anonymous occupants';
$f = ['property_id' => (int)($_GET['property_id'] ?? 0), 'status' => (string)($_GET['status'] ?? 'live'), 'occ' => (string)($_GET['occ'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'expiring' => !empty($_GET['expiring'])];
$w = [scope_sql('l.property_id')]; $p = [];
if ($f['property_id']) { $w[] = 'l.property_id = :pid'; $p['pid'] = $f['property_id']; }
if ($f['status'] === 'live') $w[] = "l.status IN ('active','notice')"; elseif ($f['status']) { $w[] = 'l.status = :st'; $p['st'] = $f['status']; }
if ($f['occ']) { $w[] = 'l.occupancy_type = :o'; $p['o'] = $f['occ']; }
if ($f['expiring']) $w[] = "l.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
if ($f['q'] !== '') { $w[] = "(l.lease_no LIKE :q OR u.unit_no LIKE :q OR EXISTS (SELECT 1 FROM bln_lease_parties x JOIN prpt_parties y ON y.id = x.party_id WHERE x.lease_id = l.id AND y.display_name LIKE :q))"; $p['q'] = '%' . $f['q'] . '%'; }
$where = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id WHERE $where", $p);
[$lim, $off, $pager] = ui_paginate($total, 40);
$rows = all("SELECT l.*, u.unit_no, pr.name AS property,
    (SELECT GROUP_CONCAT(pt.display_name ORDER BY lp.is_primary DESC SEPARATOR ', ') FROM bln_lease_parties lp JOIN prpt_parties pt ON pt.id = lp.party_id WHERE lp.lease_id = l.id AND lp.role IN ('tenant','co_tenant','occupant','payer')) AS tenants,
    (SELECT GROUP_CONCAT(pt.display_name SEPARATOR ', ') FROM bln_lease_parties lp JOIN prpt_parties pt ON pt.id = lp.party_id WHERE lp.lease_id = l.id AND lp.role = 'owner') AS owners,
    (SELECT COALESCE(SUM(balance),0) FROM bln_invoices i WHERE i.lease_id = l.id AND i.status IN ('issued','partially_paid','overdue')) AS balance,
    (SELECT COALESCE(SUM(amount),0) FROM bln_lease_charges c WHERE c.lease_id = l.id AND c.is_active = 1 AND c.billing_method <> 'metered' AND c.frequency = 'monthly') AS monthly
    FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE $where ORDER BY pr.name, u.unit_no LIMIT $lim OFFSET $off", $p);
$occ = ['tenant_managed' => 'Tenant · we collect rent', 'tenant_owner_managed' => 'Tenant · owner collects rent', 'owner_occupied' => 'Owner occupied', 'owner_vacant' => 'Owner · vacant', 'anonymous' => 'Anonymous occupant', 'company_let' => 'Company let', 'short_stay' => 'Short stay'];
$k = one("SELECT SUM(status IN ('active','notice')) live, SUM(status = 'notice') notice, SUM(end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY) AND status IN ('active','notice')) expiring, SUM(occupancy_type IN ('owner_occupied','owner_vacant') AND status = 'active') owners FROM bln_leases l WHERE " . scope_sql('l.property_id'));
if (can('billing.leases.manage')) $actions = '<a class="vk-btn-accent" href="' . e(page('billing/lease-new')) . '"><i class="fa-solid fa-file-circle-plus"></i>Open account</a>';
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
  <?= ui_stat('fa-file-contract', 'Live accounts', num($k['live']), 'accent', null, page('billing/leases')) ?>
  <?= ui_stat('fa-door-open', 'On notice', num($k['notice']), 'red', null, page('billing/leases', ['status' => 'notice'])) ?>
  <?= ui_stat('fa-hourglass-half', 'Ending in 60 days', num($k['expiring']), 'violet', null, page('billing/leases', ['expiring' => 1])) ?>
  <?= ui_stat('fa-house-user', 'Owner accounts', num($k['owners']), 'blue', null, page('billing/leases', ['occ' => 'owner_occupied'])) ?>
</div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Account no., unit or name"'), 'w-60') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All')), 'w-52') ?>
  <?= ui_field('Occupancy', ui_select('occ', ui_kv_options($occ, $f['occ'], 'Any')), 'w-56') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['live' => 'Active + notice', 'active' => 'Active', 'notice' => 'On notice', 'ended' => 'Ended', 'terminated' => 'Terminated'], $f['status'], 'All')), 'w-40') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Account</th><th>Unit</th><th>Occupancy</th><th>Tenant / occupant</th><th>Owner</th><th>Period</th><th class="num">Monthly</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $l): ?>
    <tr data-href="<?= e(page('billing/lease', ['id' => $l['id']])) ?>">
      <td><b><?= e($l['lease_no']) ?></b><?= $l['auto_invoice'] ? '' : '<span class="block text-[11px] text-amber-600">manual billing</span>' ?></td>
      <td><b><?= e($l['unit_no']) ?></b><span class="block text-xs text-muted"><?= e($l['property']) ?></span></td>
      <td class="text-xs"><?= e($occ[$l['occupancy_type']] ?? $l['occupancy_type']) ?></td>
      <td class="text-sm"><?= e($l['tenants'] ?: '—') ?></td><td class="text-sm"><?= e($l['owners'] ?: '—') ?></td>
      <td class="text-xs"><?= fdate($l['start_date']) ?><span class="block text-muted">→ <?= $l['end_date'] ? fdate($l['end_date']) : 'periodic' ?></span></td>
      <td class="num"><?= money($l['monthly'], false) ?></td><td class="num"><?= ui_money_cell($l['balance'], true) ?></td><td><?= pill($l['status']) ?></td>
    </tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="9">' . ui_empty('fa-file-contract', 'No accounts match', '', can('billing.leases.manage') ? '<a class="vk-btn-accent" href="' . e(page('billing/lease-new')) . '">Open an account</a>' : '') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
