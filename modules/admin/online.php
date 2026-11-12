<?php
$title = 'Who is online';
$subtitle = 'Staff active in the last 15 minutes and the page each one is on right now';
$rows = all("SELECT s.*, u.first_name, u.last_name, u.email, r.name AS role FROM sys_sessions s JOIN sys_users u ON u.id = s.user_id JOIN sys_roles r ON r.id = u.role_id WHERE s.ended_at IS NULL AND s.last_activity > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY s.last_activity DESC");
$portal = all("SELECT p.display_name, a.last_seen_at FROM prt_accounts a JOIN prpt_parties p ON p.id = a.party_id WHERE a.last_seen_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY a.last_seen_at DESC LIMIT 50");
?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-6 items-start">
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="online">
  <?php foreach ($rows as $s): $name = trim($s['first_name'] . ' ' . $s['last_name']); $idle = strtotime($s['last_activity']) < time() - 180; ?>
    <div class="vk-panel p-5"><div class="flex items-center gap-3"><span class="relative"><?= ui_avatar($name, 'h-11 w-11') ?><span class="absolute -bottom-0.5 -right-0.5 h-3.5 w-3.5 rounded-full <?= $idle ? 'bg-amber-400' : 'bg-emerald-500' ?> ring-2 ring-surface"></span></span>
      <div class="min-w-0 flex-1"><b><?= e($name) ?></b><span class="block text-xs text-muted"><?= e($s['role']) ?> · <?= $idle ? 'idle' : 'active' ?> <?= time_ago($s['last_activity']) ?></span></div></div>
      <a href="<?= e($s['current_path']) ?>" class="mt-4 flex items-center gap-2 rounded-xl bg-surface2/60 px-3 py-2 text-sm hover:bg-accent/10"><i class="fa-solid fa-eye text-accent"></i><span class="truncate">Viewing <b><?= e($s['current_title'] ?: 'Dashboard') ?></b></span></a>
      <p class="mt-3 text-[11px] text-muted"><i class="fa-solid fa-location-dot"></i> <?= e($s['ip_address']) ?> · signed in <?= time_ago($s['started_at']) ?> · <?= e(excerpt((string)$s['user_agent'], 50)) ?></p>
      <?php if (can('admin.users.manage') && (int)$s['user_id'] !== staff_id()): ?><button class="mt-3 vk-btn-ghost !py-1 text-xs" data-action="<?= e(url('api.php', ['r' => 'admin/kick', 'id' => $s['id']])) ?>" data-confirm="Sign <?= e($name) ?> out?"><i class="fa-solid fa-right-from-bracket"></i>Sign out</button><?php endif; ?></div>
  <?php endforeach; if (!$rows) echo '<div class="vk-panel md:col-span-2">' . ui_empty('fa-signal', 'Nobody else is online') . '</div>'; ?>
  </div>
  <div class="vk-panel p-5"><h3 class="font-bold mb-3"><i class="fa-solid fa-mobile-screen text-accent mr-2"></i>In the self-service portal</h3>
    <?php foreach ($portal as $p): ?><p class="flex justify-between text-sm py-1"><span><?= e($p['display_name']) ?></span><span class="text-xs text-muted"><?= time_ago($p['last_seen_at']) ?></span></p><?php endforeach; if (!$portal) echo '<p class="text-sm text-muted">No tenants or owners right now.</p>'; ?></div>
</div>
<?php page_script('<script>setTimeout(()=>location.reload(),60000)</script>');
