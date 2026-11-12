<?php
$title = 'Marketplace accounts';
$subtitle = 'Sellers and buyers on vikao.io — plans (subscriptions), badges, suspensions';
if (!(require __DIR__ . '/_guard.php')) return;
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? ''), 'tier' => (string)($_GET['tier'] ?? '')];
$users = ecom_users($f, 200);
$tiers = eall("SELECT * FROM membership_tiers ORDER BY rank_order");
$badges = eall("SELECT * FROM badges ORDER BY name");
$tab = $_GET['tab'] ?? 'accounts';
echo ui_tabs(['accounts' => ['Accounts', 'fa-user-group'], 'plans' => ['Plans & pricing', 'fa-gem']], $tab);
if ($tab === 'plans'): ?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
<?php foreach ($tiers as $t): $n = (int)eval_("SELECT COUNT(*) FROM users WHERE membership_tier_id = ?", [$t['id']]); ?>
  <form data-ajax="<?= e(url('api.php', ['r' => 'ecommerce/tier-save'])) ?>" class="vk-panel p-5 space-y-3"><input type="hidden" name="id" value="<?= $t['id'] ?>">
    <div class="flex items-center gap-2"><i class="fa-solid <?= e($t['icon'] ?: 'fa-gem') ?>" style="color:<?= e($t['color_hex']) ?>"></i><b><?= e($t['name']) ?></b><span class="ml-auto text-xs text-muted"><?= $n ?> member(s)</span></div>
    <?= ui_field('Monthly price (' . e($t['currency_code']) . ')', ui_input('monthly_price', $t['monthly_price'], 'number', 'step="any"')) ?>
    <?= ui_field('Max active ads (blank = unlimited)', ui_input('max_active_ads', $t['max_active_ads'], 'number')) ?>
    <?php $perks = json_decode((string)$t['perks'], true) ?: []; if ($perks): ?><ul class="text-xs text-muted list-disc pl-4"><?php foreach ($perks as $p) echo '<li>' . e(is_string($p) ? $p : json_encode($p)) . '</li>'; ?></ul><?php endif; ?>
    <button class="vk-btn-dark w-full justify-center !py-2">Save plan</button></form>
<?php endforeach; ?>
</div>
<?php return; endif; ?>
<?= ui_filter_open() ?>
  <input type="hidden" name="tab" value="accounts">
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Name, email, phone"'), 'w-60') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'pending' => 'Pending', 'suspended' => 'Suspended', 'banned' => 'Banned'], $f['status'], 'Any')), 'w-36') ?>
  <?= ui_field('Plan', ui_select('tier', ui_options($tiers, 'code', 'name', $f['tier'], 'Any')), 'w-36') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Account</th><th>Contact</th><th>Plan</th><th>Badges</th><th class="num">Ads</th><th>Status</th><th>Joined</th><th></th></tr></thead><tbody>
  <?php foreach ($users as $u): $ub = array_filter(explode(',', (string)$u['badges'])); ?><tr>
    <td><div class="flex items-center gap-3"><?= ui_avatar($u['display_name']) ?><div><b><?= e($u['display_name']) ?></b><?= $u['account_type'] === 'official' ? ' ' . pill('active', 'Official', 'amber') : '' ?><span class="block text-xs text-muted">last seen <?= time_ago($u['last_seen_at']) ?></span></div></div></td>
    <td class="text-sm"><?= e($u['email']) ?><span class="block text-xs text-muted"><?= e($u['phone']) ?></span></td>
    <td><?php if (can('ecommerce.accounts')): ?><select class="vk-select !py-1 !w-36 text-xs u-tier" data-id="<?= $u['id'] ?>"><?= ui_options($tiers, 'id', 'name', $u['membership_tier_id'], 'Free') ?></select><?php else: ?><?= e($u['tier_name'] ?: 'Free') ?><?php endif; ?></td>
    <td><div class="flex flex-wrap gap-1"><?php foreach ($badges as $b): $has = in_array($b['code'], $ub, true); ?><button type="button" title="<?= e($b['name']) ?>" class="h-7 w-7 rounded-lg border text-xs <?= $has ? 'border-transparent text-white' : 'border-line text-muted opacity-50 hover:opacity-100' ?>" style="<?= $has ? 'background:' . e($b['color_hex']) : '' ?>" <?= can('ecommerce.accounts') ? 'data-action="' . e(url('api.php', ['r' => 'ecommerce/user-update'])) . '" data-payload=\'' . e(json_encode(['id' => (int)$u['id'], 'badge' => $b['code']])) . '\'' : 'disabled' ?>><i class="fa-solid <?= e($b['icon']) ?>"></i></button><?php endforeach; ?></div></td>
    <td class="num"><?= (int)$u['active_ads'] ?> <span class="text-xs text-muted">/ <?= (int)$u['ads'] ?></span></td><td><?= pill($u['status']) ?></td><td class="text-xs"><?= fdate($u['created_at']) ?></td>
    <td class="text-right"><?php if (can('ecommerce.accounts') && $u['account_type'] !== 'official'): ?><?= $u['status'] === 'active' ? '<button class="vk-btn-ghost !py-1 text-xs !text-rose-600" data-action="' . e(url('api.php', ['r' => 'ecommerce/user-update'])) . '" data-payload=\'{"id":' . $u['id'] . ',"status":"suspended"}\' data-confirm="Suspend this account? Their ads stay hidden while suspended.">Suspend</button>' : '<button class="vk-btn-ghost !py-1 text-xs" data-action="' . e(url('api.php', ['r' => 'ecommerce/user-update'])) . '" data-payload=\'{"id":' . $u['id'] . ',"status":"active"}\'>Activate</button>' ?><?php endif; ?></td></tr>
  <?php endforeach; if (!$users) echo '<tr><td colspan="8">' . ui_empty('fa-user-group', 'No accounts') . '</td></tr>'; ?>
  </tbody></table></div></div>
<?php page_script('<script>$(".u-tier").on("change",function(){VK.api("ecommerce/user-update",{id:$(this).data("id"),membership_tier_id:this.value||0}).then(r=>VK.toast(r.message))})</script>');
