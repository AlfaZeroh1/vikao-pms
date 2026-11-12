<?php
$title = 'Staff users';
$subtitle = 'Who can sign in to the PMS, their role and which properties they can see';
$q = trim((string)($_GET['q'] ?? '')); $role = (int)($_GET['role'] ?? 0);
$w = ['u.deleted_at IS NULL']; $p = [];
if ($q !== '') { $w[] = '(u.first_name LIKE :q OR u.last_name LIKE :q OR u.email LIKE :q OR u.username LIKE :q)'; $p['q'] = "%$q%"; }
if ($role) { $w[] = 'u.role_id = :r'; $p['r'] = $role; }
$rows = all("SELECT u.*, r.name AS role, r.code AS role_code, r.scope_all_properties, (SELECT GROUP_CONCAT(pr.name SEPARATOR ', ') FROM sys_user_properties up JOIN prpt_properties pr ON pr.id = up.property_id WHERE up.user_id = u.id) AS props,
    (SELECT GROUP_CONCAT(property_id) FROM sys_user_properties up WHERE up.user_id = u.id) AS prop_ids FROM sys_users u JOIN sys_roles r ON r.id = u.role_id WHERE " . implode(' AND ', $w) . " ORDER BY u.status = 'active' DESC, u.first_name", $p);
$roles = all("SELECT id, name, scope_all_properties FROM sys_roles ORDER BY name");
if (can('admin.users.manage')) $actions = '<button class="vk-btn-accent" data-crud-new="#m-user" data-title="New staff user"><i class="fa-solid fa-user-plus"></i>Add user</button>';
?>
<?= ui_filter_open() ?><?= ui_field('Search', ui_input('q', $q, 'search', 'placeholder="Name, email, username"'), 'w-64') ?><?= ui_field('Role', ui_select('role', ui_options($roles, 'id', 'name', $role, 'Any')), 'w-48') ?><?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>User</th><th>Role</th><th>Properties</th><th>Status</th><th>Last seen</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $u): $online = $u['last_seen_at'] && strtotime($u['last_seen_at']) > time() - 300; ?><tr>
    <td><div class="flex items-center gap-3"><span class="relative"><?= ui_avatar(trim($u['first_name'] . ' ' . $u['last_name'])) ?><?= $online ? '<span class="absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full bg-emerald-500 ring-2 ring-surface"></span>' : '' ?></span><div><a class="font-bold hover:text-accent" href="<?= e(page('admin/user', ['id' => $u['id']])) ?>"><?= e(trim($u['first_name'] . ' ' . $u['last_name'])) ?></a><span class="block text-xs text-muted"><?= e($u['email']) ?><?= $u['username'] ? ' · @' . e($u['username']) : '' ?><?= $u['job_title'] ? ' · ' . e($u['job_title']) : '' ?></span></div></div></td>
    <td><?= e($u['role']) ?></td><td class="text-xs"><?= $u['scope_all_properties'] || $u['role_code'] === 'super_admin' ? '<span class="text-muted">All properties</span>' : e($u['props'] ?: 'None assigned') ?></td>
    <td><?= pill($u['status']) ?><?= $u['must_change_password'] ? '<span class="block text-[11px] text-amber-600">must set password</span>' : '' ?></td><td class="text-xs"><?= $online ? '<span class="text-emerald-600 font-bold">online</span>' : time_ago($u['last_seen_at']) ?></td>
    <td class="text-right"><?php if (can('admin.users.manage')): ?><button class="vk-icon-btn !h-8 !w-8" data-crud-edit="#m-user" data-row='<?= e(json_encode(array_diff_key($u, ['password_hash' => 1]))) ?>' data-title="Edit <?= e($u['first_name']) ?>"><i class="fa-solid fa-pen text-xs"></i></button><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php if (can('admin.users.manage')):
$props = properties_for_select();
$body = '<input type="hidden" name="id"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('First name *', ui_input('first_name', '', 'text', 'required')) . ui_field('Last name', ui_input('last_name'))
  . ui_field('Email *', ui_input('email', '', 'email', 'required')) . ui_field('Username (optional login)', ui_input('username')) . ui_field('Phone', ui_input('phone')) . ui_field('Job title', ui_input('job_title'))
  . ui_field('Role *', '<select name="role_id" class="vk-select" required id="u-role">' . implode('', array_map(fn($r) => '<option value="' . $r['id'] . '" data-all="' . (int)$r['scope_all_properties'] . '">' . e($r['name']) . '</option>', $roles)) . '</select>')
  . ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'suspended' => 'Suspended', 'invited' => 'Invited'])))
  . ui_field('Password <span class="font-normal text-muted">(blank = auto / unchanged)</span>', '<input type="password" name="password" class="vk-input" autocomplete="new-password">')
  . '<div class="pt-6"><label class="vk-switch"><input type="checkbox" name="send_welcome" value="1" checked>Email login details (new users)</label></div></div>'
  . '<div id="u-props"><span class="vk-label">Properties this user can see</span><div class="flex flex-wrap gap-2">' . implode('', array_map(fn($p) => '<label class="vk-choice !py-1"><input type="checkbox" name="property_ids[]" value="' . $p['id'] . '"><span class="text-xs">' . e($p['name']) . '</span></label>', $props)) . '</div></div>';
echo ui_modal('m-user', 'Staff user', $body, url('api.php', ['r' => 'admin/save-user']), 'Save user', 'max-w-3xl', 'fa-user-gear');
page_script('<script>$("#u-role").on("change",function(){$("#u-props").toggle(!$(this).find(":selected").data("all"))});$(document).on("click","[data-crud-edit=\'#m-user\']",function(){const r=$(this).data("row");const ids=String(r.prop_ids||"").split(",");$("#m-user [name=\'property_ids[]\']").each(function(){this.checked=ids.includes(this.value)});setTimeout(()=>$("#u-role").trigger("change"),0)});$(document).on("click","[data-crud-new=\'#m-user\']",()=>setTimeout(()=>$("#u-role").trigger("change"),0))</script>');
endif;
