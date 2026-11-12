<?php
require_once __DIR__ . '/_partials.php';
$title = 'Owners & tenants';
$role = (string)($_GET['role'] ?? ''); $q = trim((string)($_GET['q'] ?? '')); $portal = (string)($_GET['portal'] ?? '');
$w = ['p.deleted_at IS NULL']; $prm = [];
if (in_array($role, ['owner', 'tenant', 'guest', 'buyer'], true)) $w[] = "p.is_$role = 1";
if ($q !== '') { $w[] = '(p.display_name LIKE :q OR p.phone LIKE :q OR p.email LIKE :q OR p.id_number LIKE :q OR p.party_no LIKE :q)'; $prm['q'] = "%$q%"; }
if ($portal === 'active') $w[] = "a.status = 'active'"; elseif ($portal === 'pending') $w[] = "a.status = 'pending'"; elseif ($portal === 'none') $w[] = 'a.id IS NULL';
$where = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM prpt_parties p LEFT JOIN prt_accounts a ON a.party_id = p.id WHERE $where", $prm);
[$lim, $off, $pager] = ui_paginate($total, 40);
$rows = all("SELECT p.*, a.status AS portal_status, a.activation_sent_at, a.last_login_at,
    (SELECT COUNT(*) FROM bln_lease_parties lp JOIN bln_leases l ON l.id = lp.lease_id WHERE lp.party_id = p.id AND lp.role IN ('tenant','co_tenant') AND l.status IN ('active','notice')) AS rentals,
    (SELECT COUNT(*) FROM prpt_ownerships o WHERE o.party_id = p.id AND (o.end_date IS NULL OR o.end_date >= CURDATE())) AS holdings,
    (SELECT COALESCE(SUM(balance),0) FROM bln_invoices i WHERE i.party_id = p.id AND i.status IN ('issued','partially_paid','overdue')) AS balance
    FROM prpt_parties p LEFT JOIN prt_accounts a ON a.party_id = p.id WHERE $where ORDER BY p.display_name LIMIT $lim OFFSET $off", $prm);
$c = one("SELECT SUM(is_owner) o, SUM(is_tenant) t, SUM(is_guest) g, COUNT(*) n FROM prpt_parties WHERE deleted_at IS NULL");
if (can('property.parties.manage')) $actions = '<button class="vk-btn-accent" data-modal-open="#m-party" id="btn-new-party"><i class="fa-solid fa-user-plus"></i>New owner / tenant</button>';
?>
<div class="flex flex-wrap gap-2 mb-4">
  <?php foreach (['' => ['Everyone', $c['n']], 'owner' => ['Home owners', $c['o']], 'tenant' => ['Tenants', $c['t']], 'guest' => ['Short-stay guests', $c['g']]] as $k => [$l, $n]): ?>
    <a href="<?= e(page('property/parties', array_filter(['role' => $k]))) ?>" class="vk-chip <?= $role === $k ? '!border-accent !bg-accent/10' : '' ?>"><?= $l ?> <b><?= (int)$n ?></b></a>
  <?php endforeach; ?>
</div>
<?= ui_filter_open() ?>
  <input type="hidden" name="role" value="<?= e($role) ?>">
  <?= ui_field('Search', ui_input('q', $q, 'search', 'placeholder="Name, phone, email, ID, party no."'), 'w-72') ?>
  <?= ui_field('Portal', ui_select('portal', ui_kv_options(['active' => 'Activated', 'pending' => 'Invited / pending', 'none' => 'No account'], $portal, 'Any')), 'w-44') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden">
  <div class="overflow-x-auto"><table class="vk-table">
    <thead><tr><th>Person</th><th>Contact</th><th>Roles</th><th>Holdings</th><th>Portal</th><th class="num">Balance</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr data-href="<?= e(page('property/party', ['id' => $p['id']])) ?>">
        <td><div class="flex items-center gap-3"><?= ui_avatar($p['display_name']) ?><div><b><?= e($p['display_name']) ?></b><span class="block text-xs text-muted"><?= e($p['party_no']) ?><?= $p['id_number'] ? ' · ID ' . e($p['id_number']) : '' ?></span></div></div></td>
        <td class="text-sm"><?= e($p['phone'] ?: '—') ?><span class="block text-xs text-muted"><?= e($p['email'] ?: '') ?></span></td>
        <td><div class="flex flex-wrap gap-1"><?= $p['is_owner'] ? pill('owner', 'Owner', 'violet') : '' ?><?= $p['is_tenant'] ? pill('tenant', 'Tenant', 'blue') : '' ?><?= $p['is_guest'] ? pill('guest', 'Guest', 'amber') : '' ?><?= $p['is_buyer'] ? pill('buyer', 'Buyer', 'green') : '' ?></div></td>
        <td class="text-xs"><?= $p['holdings'] ? $p['holdings'] . ' ownership' . ($p['holdings'] > 1 ? 's' : '') : '' ?><?= $p['rentals'] ? ($p['holdings'] ? ' · ' : '') . $p['rentals'] . ' rental' . ($p['rentals'] > 1 ? 's' : '') : '' ?></td>
        <td><?= $p['portal_status'] === 'active' ? pill('active', 'Active') . '<span class="block text-[11px] text-muted">' . time_ago($p['last_login_at']) . '</span>' : ($p['portal_status'] === 'pending' ? pill('pending', $p['activation_sent_at'] ? 'Invited' : 'Not invited') : ($p['portal_status'] === 'disabled' ? pill('suspended', 'Disabled') : '<span class="text-xs text-muted">—</span>')) ?></td>
        <td class="num"><?= ui_money_cell($p['balance'], true) ?></td>
      </tr>
    <?php endforeach; if (!$rows) echo '<tr><td colspan="6">' . ui_empty('fa-users', 'No people match') . '</td></tr>'; ?>
    </tbody></table></div>
  <?= $pager ?>
</div>
<?php if (can('property.parties.manage')) { echo party_modal_html(); if (!empty($_GET['new'])) page_script('<script>$(()=>VK.openModal("#m-party"))</script>'); }
