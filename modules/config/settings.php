<?php
$title = 'Settings';
$subtitle = 'Company details, billing rules, payments, meters, tax, portal';
$rows = all("SELECT * FROM cfg_settings ORDER BY FIELD(group_name,'company','billing','payments','tax','meters','sales','crm','portal'), group_name, label");
$groups = []; foreach ($rows as $r) $groups[$r['group_name']][] = $r;
$icons = ['company' => 'fa-building', 'billing' => 'fa-file-invoice', 'payments' => 'fa-money-bill', 'tax' => 'fa-percent', 'meters' => 'fa-gauge', 'sales' => 'fa-handshake', 'crm' => 'fa-headset', 'portal' => 'fa-mobile-screen'];
?>
<form data-ajax="<?= e(url('api.php', ['r' => 'config/save-settings'])) ?>" class="space-y-5 max-w-4xl" data-stay>
<?php foreach ($groups as $g => $list): ?>
  <section class="vk-panel p-5"><h3 class="font-bold mb-4"><i class="fa-solid <?= $icons[$g] ?? 'fa-gear' ?> text-accent mr-2"></i><?= e(ucfirst($g)) ?></h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <?php foreach ($list as $s): $n = 's_' . $s['setting_key']; ?>
      <?php if ($s['value_type'] === 'bool'): ?><div class="sm:col-span-2"><input type="hidden" name="<?= $n ?>__present" value="1"><label class="vk-switch"><input type="checkbox" name="<?= $n ?>" value="1" <?= $s['setting_value'] === '1' ? 'checked' : '' ?>><?= e($s['label']) ?></label></div>
      <?php elseif ($s['value_type'] === 'text'): ?><div class="sm:col-span-2"><?= ui_field(e($s['label']), '<textarea name="' . $n . '" rows="3" class="vk-textarea">' . e($s['setting_value']) . '</textarea>') ?></div>
      <?php else: ?><?= ui_field(e($s['label']), ui_input($n, $s['setting_value'], in_array($s['value_type'], ['int', 'decimal'], true) ? 'number' : 'text', $s['value_type'] === 'decimal' ? 'step="any"' : '')) ?><?php endif; ?>
    <?php endforeach; ?></div></section>
<?php endforeach; ?>
  <div class="sticky bottom-3"><button class="vk-btn-accent shadow-xl"><i class="fa-solid fa-floppy-disk"></i>Save settings</button></div>
</form>
