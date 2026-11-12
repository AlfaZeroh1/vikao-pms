<?php
$title = 'Notifications';
$rows = all("SELECT * FROM sys_notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 200", [$me['id']]);
$actions = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'dashboard/read-notifications'])) . '"><i class="fa-solid fa-check-double"></i>Mark all read</button>';
?>
<div class="vk-panel divide-y divide-line/60 overflow-hidden max-w-3xl">
  <?php foreach ($rows as $n): ?>
    <a href="<?= e($n['link_url'] ?: '#') ?>" class="flex gap-3 p-4 hover:bg-surface2/50 <?= $n['read_at'] ? '' : 'bg-accent/[.04]' ?>">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-surface2 text-accent"><i class="fa-solid <?= e($n['icon'] ?: 'fa-bell') ?>"></i></span>
      <span class="min-w-0 flex-1"><span class="block font-semibold"><?= e($n['title']) ?></span><span class="block text-sm text-muted"><?= e($n['body']) ?></span><span class="text-[11px] text-muted"><?= time_ago($n['created_at']) ?></span></span>
      <?php if (!$n['read_at']): ?><span class="h-2.5 w-2.5 rounded-full bg-accent mt-2"></span><?php endif; ?>
    </a>
  <?php endforeach; if (!$rows) echo ui_empty('fa-bell-slash', 'No notifications'); ?>
</div>
<?php q("UPDATE sys_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [$me['id']]); ?>
