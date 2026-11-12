<?php
$title = 'Marketplace API';
$subtitle = 'vikao.io has its own M-Pesa and Stripe accounts, separate from PMS rent collection';
require_once __DIR__ . '/../config/_integration_editor.php';
$code = (string)($_GET['code'] ?? 'mpesa_ecom');
$list = all("SELECT * FROM api_integrations WHERE scope = 'ecommerce' ORDER BY name");
$cur = one("SELECT * FROM api_integrations WHERE code = ? AND scope = 'ecommerce'", [$code]);
$methods = ecom_ok() ? eall("SELECT * FROM payment_methods ORDER BY sort_order") : [];
$mbase = rtrim((string)config('ecom_db.site_url'), '/');
?>
<div class="grid grid-cols-1 xl:grid-cols-[340px_1fr] gap-6 items-start">
  <div class="space-y-4">
    <div class="vk-panel p-2"><?php foreach ($list as $i): ?><a href="<?= e(page('ecommerce/api', ['code' => $i['code']])) ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 <?= $code === $i['code'] ? 'bg-accent/10' : 'hover:bg-surface2' ?>"><i class="fa-solid <?= $i['provider'] === 'mpesa' ? 'fa-mobile-screen' : 'fa-credit-card' ?> text-accent"></i><b class="text-sm flex-1"><?= e($i['name']) ?></b><?= $i['simulate'] ? pill('pending', 'Simulate') : pill('active', 'Live') ?></a><?php endforeach; ?></div>
    <div class="vk-panel p-5"><h3 class="font-bold text-sm mb-3">Checkout payment methods on vikao.io</h3>
      <?php foreach ($methods as $m): ?><div class="flex items-center gap-3 py-2 border-b border-line/60 last:border-0"><i class="fa-solid <?= e($m['icon'] ?: 'fa-wallet') ?> text-muted"></i><b class="text-sm flex-1"><?= e($m['name']) ?></b>
        <label class="vk-switch text-xs"><input type="checkbox" class="pm-t" data-id="<?= $m['id'] ?>" data-field="is_active" <?= $m['is_active'] ? 'checked' : '' ?>>On</label>
        <label class="vk-switch text-xs"><input type="checkbox" class="pm-t" data-id="<?= $m['id'] ?>" data-field="is_simulated" <?= $m['is_simulated'] ? 'checked' : '' ?>>Sim</label></div><?php endforeach; if (!$methods) echo '<p class="text-xs text-muted">Marketplace not connected.</p>'; ?>
      <p class="text-[11px] text-muted mt-3">Marketplace callback URLs: <code><?= e($mbase) ?>/api/payments.php?action=callback</code></p></div>
  </div>
  <?= $cur ? integration_editor_html($cur) : '<div class="vk-panel">' . ui_empty('fa-plug', 'No marketplace integrations') . '</div>' ?>
</div>
<?php page_script('<script>$(".pm-t").on("change",function(){VK.api("ecommerce/method-toggle",{id:$(this).data("id"),field:$(this).data("field"),on:this.checked?1:0}).then(r=>VK.toast(r.message))})</script>');
