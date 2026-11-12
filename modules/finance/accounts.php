<?php
$title = 'Collection accounts';
$subtitle = 'Vikao master accounts (paybill, Co-op Bank, Stripe) and any property- or owner-specific accounts payments land in';
$map = all("SELECT pa.*, pr.name AS property, fa.name AS account, fa.number FROM fin_property_accounts pa JOIN prpt_properties pr ON pr.id = pa.property_id JOIN fin_accounts fa ON fa.id = pa.account_id WHERE " . scope_sql('pa.property_id') . " ORDER BY pr.name, pa.is_default DESC");
$bal = all("SELECT fa.id, fa.name, fa.account_type, fa.opening_balance, COALESCE(SUM(CASE WHEN pm.status <> 'reversed' THEN pm.amount END),0) received, COALESCE(SUM(CASE WHEN pm.status <> 'reversed' AND pm.paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN pm.amount END),0) mtd
    FROM fin_accounts fa LEFT JOIN fin_payments pm ON pm.account_id = fa.id WHERE fa.is_active = 1 GROUP BY fa.id ORDER BY fa.is_master DESC, received DESC");
?>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3 mb-6">
  <?php foreach (array_slice($bal, 0, 8) as $b): ?><?= ui_stat(match ($b['account_type']) { 'mpesa_paybill', 'mpesa_till' => 'fa-mobile-screen', 'stripe' => 'fa-credit-card', 'cash' => 'fa-money-bills', default => 'fa-building-columns' }, $b['name'], money($b['mtd'], true, true), 'green', 'this month · ' . money($b['received'], true, true) . ' all-time') ?><?php endforeach; ?>
</div>
<?= render_crud('fin_accounts', [], ['title' => 'Accounts', 'empty' => 'Add the Vikao paybill, Co-op Bank and Stripe accounts first.']) ?>
<div class="vk-panel overflow-hidden mt-6">
  <div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold"><i class="fa-solid fa-link text-accent mr-2"></i>Which account each property collects into</h3><button class="ml-auto vk-btn-accent !py-2 text-sm" data-modal-open="#m-map"><i class="fa-solid fa-plus"></i>Link</button></div>
  <table class="vk-table"><thead><tr><th>Property</th><th>Account</th><th>Account reference format</th><th>Default</th><th></th></tr></thead><tbody>
  <?php foreach ($map as $m): ?><tr><td><b><?= e($m['property']) ?></b></td><td><?= e($m['account']) ?> <span class="text-xs text-muted"><?= e($m['number']) ?></span></td><td class="font-mono text-xs"><?= e($m['account_reference_format']) ?></td><td><?= $m['is_default'] ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '' ?></td>
    <td class="text-right"><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'property/property-account'])) ?>" data-payload='<?= e(json_encode(['property_id' => $m['property_id'], 'account_id' => $m['account_id'], 'remove' => 1])) ?>' data-confirm="Unlink this account?"><i class="fa-solid fa-link-slash text-xs"></i></button></td></tr><?php endforeach; ?>
  </tbody></table><?= $map ? '' : ui_empty('fa-link', 'No property links yet', 'Unlinked properties collect into the Vikao master accounts.') ?>
</div>
<?= ui_modal('m-map', 'Link property to account', '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Property *', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name'))) . ui_field('Account *', ui_select('account_id', ui_options(all("SELECT id, name FROM fin_accounts WHERE is_active = 1"), 'id', 'name')))
  . ui_field('Account reference format', ui_input('account_reference_format', '{unit_no}'), 'sm:col-span-2') . '</div><p class="text-xs text-muted">What payers type as the account number, e.g. <code>{unit_no}</code>, <code>{property_code}{unit_no}</code> or <code>{lease_no}</code>. Matching also tries the block prefix and the payer\'s phone.</p><label class="vk-switch"><input type="checkbox" name="is_default" value="1" checked>Default account for this property</label>', url('api.php', ['r' => 'property/property-account']), 'Link', 'max-w-xl', 'fa-link') ?>
