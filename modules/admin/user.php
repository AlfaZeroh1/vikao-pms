<?php
$u = one("SELECT u.*, r.name AS role FROM sys_users u JOIN sys_roles r ON r.id = u.role_id WHERE u.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$u) { echo ui_empty('fa-user', 'User not found'); return; }
$title = trim($u['first_name'] . ' ' . $u['last_name']); $subtitle = e($u['role']) . ' · ' . pill($u['status']);
$crumbs = [['Staff users', page('admin/users')]];
$sess = all("SELECT * FROM sys_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 15", [$u['id']]);
$log = all("SELECT * FROM sys_audit_log WHERE actor_type = 'staff' AND actor_id = ? ORDER BY id DESC LIMIT 60", [$u['id']]);
if ($u['id'] != staff_id()) $actions = '<button class="vk-btn-ghost !text-rose-600" data-action="' . e(url('api.php', ['r' => 'admin/delete-user', 'id' => $u['id']])) . '" data-confirm="Remove this user? They lose access immediately."><i class="fa-regular fa-trash-can"></i>Remove</button>';
?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_380px] gap-6 items-start">
  <div class="vk-panel p-6"><h3 class="font-bold mb-4">What they did</h3><?= $log ? ui_timeline(array_map(fn($a) => ['fa-circle-dot', 'blue', e($a['summary'] ?: $a['action']), '<span class="text-xs">' . e($a['module'] . ' · ' . $a['action']) . '</span>', fdatetime(substr($a['created_at'], 0, 19)), $a['ip_address']], $log)) : ui_empty('fa-clock-rotate-left', 'No activity yet') ?></div>
  <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Sessions</div><table class="vk-table"><tbody>
    <?php foreach ($sess as $s): $live = !$s['ended_at'] && strtotime($s['last_activity']) > time() - 900; ?><tr><td class="text-xs"><b><?= fdatetime($s['started_at']) ?></b><span class="block text-muted"><?= e($s['ip_address']) ?> · <?= e(excerpt((string)$s['user_agent'], 40)) ?></span><span class="block">Last: <?= e($s['current_title'] ?: '—') ?> · <?= time_ago($s['last_activity']) ?></span></td>
      <td class="text-right"><?php if ($live && can('admin.users.manage')): ?><button class="vk-btn-ghost !py-1 text-xs" data-action="<?= e(url('api.php', ['r' => 'admin/kick', 'id' => $s['id']])) ?>" data-confirm="Sign this session out?">End</button><?php else: ?><?= $s['ended_at'] ? pill('ended') : pill('pending', 'idle') ?><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
