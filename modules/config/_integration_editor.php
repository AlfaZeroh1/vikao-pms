<?php
/** Integration credential editor (used by Configuration and the Ecommerce API page). */
if (!defined('INTEGRATION_FIELDS')) require __DIR__ . '/_api.php';
function integration_editor_html(array $cur): string {
    ob_start(); ?>
  <?php $fields = INTEGRATION_FIELDS[$cur['provider']] ?? ['creds' => [], 'cfg' => []]; $creds = json_decode((string)$cur['credentials'], true) ?: []; $cfg = json_decode((string)$cur['settings'], true) ?: [];
    $logs = all("SELECT * FROM api_logs WHERE integration_code = ? ORDER BY id DESC LIMIT 25", [$cur['code']]); ?>
  <div class="space-y-5">
    <form data-ajax="<?= e(url('api.php', ['r' => 'config/save-integration'])) ?>" class="vk-panel p-6 space-y-5" data-stay>
      <input type="hidden" name="code" value="<?= e($cur['code']) ?>">
      <div class="flex flex-wrap items-center gap-3"><h3 class="font-bold text-lg"><?= e($cur['name']) ?></h3><span class="text-xs text-muted font-mono"><?= e($cur['code']) ?></span>
        <button type="button" class="ml-auto vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'config/test-integration', 'code' => $cur['code']])) ?>" data-no-reload><i class="fa-solid fa-vial"></i>Test connection</button></div>
      <div class="flex flex-wrap gap-6"><label class="vk-switch"><input type="checkbox" name="is_active" value="1" <?= $cur['is_active'] ? 'checked' : '' ?>>Enabled</label>
        <label class="vk-switch"><input type="checkbox" name="simulate" value="1" <?= $cur['simulate'] ? 'checked' : '' ?>>Simulate (no real calls — safe for testing)</label>
        <label class="flex items-center gap-2 text-sm font-semibold">Environment <select name="environment" class="vk-select !w-36 !py-1.5"><?= ui_kv_options(['sandbox' => 'Sandbox', 'live' => 'Live / production'], $cur['environment']) ?></select></label></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <?php foreach ($fields['creds'] as $k => $l): ?><div><?= ui_field(e($l) . ' <i class="fa-solid fa-lock text-[10px] text-muted"></i>', '<input type="password" name="creds[' . $k . ']" class="vk-input" autocomplete="new-password" placeholder="' . (isset($creds[$k]) ? '•••••••• (saved — leave blank to keep)' : 'Not set') . '">') ?><?php if (isset($creds[$k])): ?><label class="text-[11px] text-muted"><input type="checkbox" name="clear[<?= $k ?>]" value="1"> clear</label><?php endif; ?></div><?php endforeach; ?>
        <?php foreach ($fields['cfg'] as $k => $l) echo ui_field(e($l), ui_input('cfg[' . $k . ']', $cfg[$k] ?? '')); ?>
      </div>
      <?php if ($cur['last_error']): ?><p class="text-xs text-rose-600"><i class="fa-solid fa-triangle-exclamation"></i> Last error: <?= e($cur['last_error']) ?></p><?php endif; ?>
      <p class="text-xs text-muted">Last success: <?= $cur['last_success_at'] ? fdatetime($cur['last_success_at']) : 'never' ?></p>
      <button class="vk-btn-accent"><i class="fa-solid fa-floppy-disk"></i>Save</button>
    </form>
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Recent calls</div><div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>When</th><th>Direction</th><th>Endpoint</th><th>HTTP</th><th class="num">ms</th><th></th></tr></thead><tbody>
      <?php foreach ($logs as $g): ?><tr><td class="text-xs"><?= fdatetime($g['created_at']) ?></td><td class="text-xs"><?= e($g['direction']) ?></td><td class="font-mono text-[11px] max-w-[320px] truncate"><?= e($g['endpoint']) ?></td><td><?= $g['success'] ? pill('active', (string)$g['http_status']) : pill('overdue', (string)($g['http_status'] ?: 'ERR')) ?></td><td class="num text-xs"><?= (int)$g['duration_ms'] ?></td>
        <td><button class="text-xs text-accent" onclick='Swal.fire({width:760,title:"Payload",html:"<pre class=\"text-left text-[11px] whitespace-pre-wrap max-h-[60vh] overflow-auto\">"+VK.esc(<?= e(json_encode("REQUEST\n" . mb_substr((string)$g['request_body'], 0, 3000) . "\n\nRESPONSE\n" . mb_substr((string)$g['response_body'], 0, 3000))) ?>)+"</pre>"})'>view</button></td></tr><?php endforeach; ?>
    </tbody></table></div><?= $logs ? '' : ui_empty('fa-list', 'No calls logged yet') ?></div>
  </div>
<?php return ob_get_clean();
}
