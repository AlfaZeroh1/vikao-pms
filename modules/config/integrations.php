<?php
$title = 'API integrations';
$subtitle = 'The “silent” API module — M-Pesa, Co-op Bank, Stripe, SMTP, SMS and AI. Credentials are encrypted at rest; every call is logged.';
$code = (string)($_GET['code'] ?? '');
$list = all("SELECT * FROM api_integrations ORDER BY FIELD(scope,'pms','shared','ecommerce'), name");
$cur = $code ? one("SELECT * FROM api_integrations WHERE code = ?", [$code]) : null;
require_once __DIR__ . '/_integration_editor.php';
?>
<div class="grid grid-cols-1 xl:grid-cols-[340px_1fr] gap-6 items-start">
  <div class="vk-panel p-2">
    <?php foreach ($list as $i): ?><a href="<?= e(page('config/integrations', ['code' => $i['code']])) ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 <?= $code === $i['code'] ? 'bg-accent/10' : 'hover:bg-surface2' ?>">
      <span class="grid h-9 w-9 place-items-center rounded-xl bg-surface2"><i class="fa-solid <?= ['mpesa' => 'fa-mobile-screen', 'coop' => 'fa-building-columns', 'stripe' => 'fa-credit-card', 'smtp' => 'fa-envelope', 'africastalking' => 'fa-comment-sms', 'anthropic' => 'fa-wand-magic-sparkles'][$i['provider']] ?? 'fa-plug' ?>"></i></span>
      <span class="flex-1 min-w-0"><b class="block text-sm truncate"><?= e($i['name']) ?></b><span class="text-[11px] text-muted"><?= e($i['scope']) ?> · <?= e($i['environment']) ?><?= $i['last_error'] ? ' · <span class="text-rose-600">error</span>' : '' ?></span></span>
      <?= !$i['is_active'] ? pill('ended', 'Off') : ($i['simulate'] ? pill('pending', 'Simulate') : pill('active', 'Live')) ?></a><?php endforeach; ?>
  </div>
  <?php if ($cur): echo integration_editor_html($cur); ?>
  <?php else: ?><div class="vk-panel"><?= ui_empty('fa-plug', 'Choose an integration', 'Everything runs in simulate mode until you add credentials and switch simulate off.') ?></div><?php endif; ?>
</div>
