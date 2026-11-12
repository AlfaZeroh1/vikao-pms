<?php
$title = 'Roles & permissions';
$subtitle = 'Define what each role can see and do — then assign roles to staff';
$roles = all("SELECT r.*, (SELECT COUNT(*) FROM sys_users u WHERE u.role_id = r.id AND u.deleted_at IS NULL) AS users, (SELECT COUNT(*) FROM sys_role_permissions rp WHERE rp.role_id = r.id) AS perms FROM sys_roles r ORDER BY r.is_system DESC, r.name");
$id = (int)($_GET['id'] ?? ($roles[1]['id'] ?? $roles[0]['id'] ?? 0));
$role = one("SELECT * FROM sys_roles WHERE id = ?", [$id]);
$perms = all("SELECT * FROM sys_permissions ORDER BY sort_order");
$has = array_flip(array_map('intval', col("SELECT permission_id FROM sys_role_permissions WHERE role_id = ?", [$id])));
$byMod = []; foreach ($perms as $p) $byMod[$p['module']][] = $p;
$actions = '<button class="vk-btn-accent" data-crud-new="#m-role" data-title="New role"><i class="fa-solid fa-plus"></i>New role</button>';
$isSuper = $role && $role['code'] === 'super_admin';
?>
<div class="grid grid-cols-1 xl:grid-cols-[300px_1fr] gap-6 items-start">
  <div class="vk-panel p-2"><?php foreach ($roles as $r): ?><a href="<?= e(page('admin/roles', ['id' => $r['id']])) ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 <?= $id === (int)$r['id'] ? 'bg-accent/10' : 'hover:bg-surface2' ?>"><i class="fa-solid <?= $r['code'] === 'super_admin' ? 'fa-crown text-amber-500' : 'fa-key text-muted' ?>"></i><span class="flex-1"><b class="text-sm block"><?= e($r['name']) ?></b><span class="text-[11px] text-muted"><?= (int)$r['users'] ?> user(s) · <?= $r['code'] === 'super_admin' ? 'everything' : (int)$r['perms'] . ' permissions' ?></span></span></a><?php endforeach; ?></div>
  <?php if ($role): ?>
  <div class="space-y-4">
    <div class="vk-panel p-5 flex flex-wrap items-center gap-3"><div><h3 class="font-bold text-lg"><?= e($role['name']) ?></h3><p class="text-sm text-muted"><?= e($role['description']) ?> · <?= $role['scope_all_properties'] ? 'sees all properties' : 'sees only assigned properties' ?></p></div>
      <div class="ml-auto flex gap-2"><?php if (!$isSuper): ?><button class="vk-btn-ghost !py-2 text-sm" data-crud-edit="#m-role" data-row='<?= e(json_encode($role)) ?>' data-title="Edit role"><i class="fa-solid fa-pen"></i>Edit</button><?php endif; ?>
        <?php if (!$role['is_system']): ?><button class="vk-btn-ghost !py-2 text-sm !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'admin/delete-role', 'id' => $role['id']])) ?>" data-confirm="Delete this role?"><i class="fa-regular fa-trash-can"></i></button><?php endif; ?></div></div>
    <?php if ($isSuper): ?><div class="vk-panel p-6 text-sm"><i class="fa-solid fa-crown text-amber-500 mr-2"></i>Super admins always have every permission, including new ones added in future updates.</div>
    <?php else: ?>
    <form data-ajax="<?= e(url('api.php', ['r' => 'admin/role-permissions'])) ?>" class="space-y-4" data-stay><input type="hidden" name="role_id" value="<?= $id ?>">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <?php foreach ($byMod as $mod => $list): ?>
        <div class="vk-panel p-4"><div class="flex items-center mb-2"><b class="capitalize"><?= e($mod) ?></b><label class="ml-auto text-xs text-muted flex items-center gap-1"><input type="checkbox" class="mod-all accent-amber-600">all</label></div>
          <?php foreach ($list as $p): ?><label class="flex items-start gap-2 py-1 text-sm"><input type="checkbox" name="perms[]" value="<?= $p['id'] ?>" class="mt-1 accent-amber-600" <?= isset($has[(int)$p['id']]) ? 'checked' : '' ?>><span><?= e($p['name']) ?><span class="block text-[11px] text-muted font-mono"><?= e($p['code']) ?></span></span></label><?php endforeach; ?></div>
      <?php endforeach; ?></div>
      <div class="sticky bottom-3"><button class="vk-btn-accent shadow-xl"><i class="fa-solid fa-floppy-disk"></i>Save permissions</button></div>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?= ui_modal('m-role', 'Role', '<input type="hidden" name="id"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Name *', ui_input('name', '', 'text', 'required')) . ui_field('Code', ui_input('code', '', 'text', 'placeholder="auto"')) . '<div class="sm:col-span-2">' . ui_field('Description', ui_input('description')) . '</div></div><label class="vk-switch"><input type="checkbox" name="scope_all_properties" value="1" checked>Can see every property (untick to limit users to assigned properties)</label>', url('api.php', ['r' => 'admin/save-role']), 'Save role', 'max-w-xl', 'fa-key') ?>
<?php page_script('<script>$(".mod-all").on("change",function(){$(this).closest(".vk-panel").find("[name=\'perms[]\']").prop("checked",this.checked)})</script>');
