<?php
$title = 'My profile';
$sessions = all("SELECT * FROM sys_sessions WHERE user_id = ? ORDER BY started_at DESC LIMIT 8", [$me['id']]);
?>
<?php if (!empty($_GET['force']) || $me['must_change_password']): ?><div class="vk-alert-info mb-5"><i class="fa-solid fa-key text-sky-500"></i> For security, set a new password before you continue.</div><?php endif; ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <form data-ajax="<?= e(url('api.php', ['r' => 'dashboard/profile'])) ?>" class="vk-panel p-6 space-y-4">
    <h2 class="font-bold">Personal details</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <?= ui_field('First name', ui_input('first_name', $me['first_name'])) ?><?= ui_field('Last name', ui_input('last_name', $me['last_name'])) ?>
      <?= ui_field('Email', ui_input('email', $me['email'], 'email')) ?><?= ui_field('Phone', ui_input('phone', $me['phone'])) ?>
      <?= ui_field('Job title', ui_input('job_title', $me['job_title'])) ?><?= ui_field('Username', '<input class="vk-input opacity-70" value="' . e($me['username']) . '" disabled>') ?>
    </div>
    <button class="vk-btn-accent"><i class="fa-solid fa-floppy-disk"></i>Save</button>
  </form>
  <form data-ajax="<?= e(url('api.php', ['r' => 'dashboard/password'])) ?>" class="vk-panel p-6 space-y-4">
    <h2 class="font-bold">Password</h2>
    <?php if (!$me['must_change_password']): ?><?= ui_field('Current password', ui_input('current_password', '', 'password')) ?><?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><?= ui_field('New password', ui_input('new_password', '', 'password')) ?><?= ui_field('Confirm new password', ui_input('confirm_password', '', 'password')) ?></div>
    <button class="vk-btn-dark"><i class="fa-solid fa-lock"></i>Update password</button>
  </form>
</div>
<div class="vk-panel overflow-hidden mt-6">
  <div class="px-5 py-4 border-b border-line font-bold">Recent sessions</div>
  <table class="vk-table"><thead><tr><th>Started</th><th>Last activity</th><th>Last page</th><th>IP</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($sessions as $s): ?><tr><td><?= fdatetime($s['started_at']) ?></td><td><?= time_ago($s['last_activity']) ?></td><td class="text-xs"><?= e($s['current_title']) ?></td><td class="text-xs"><?= e($s['ip_address']) ?></td><td><?= $s['ended_at'] ? pill('ended') : pill('active', 'Active') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
