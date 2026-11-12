<?php
$title = 'Audit trail';
$subtitle = 'Every create, edit, delete, payment, approval and login — who, what, when, from where';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'module' => (string)($_GET['module'] ?? ''), 'user' => (int)($_GET['user'] ?? 0), 'actor' => (string)($_GET['actor'] ?? ''), 'from' => (string)($_GET['from'] ?? date('Y-m-d', strtotime('-7 days'))), 'to' => (string)($_GET['to'] ?? date('Y-m-d')), 'entity' => (string)($_GET['entity'] ?? ''), 'entity_id' => (int)($_GET['entity_id'] ?? 0)];
$w = ['DATE(a.created_at) BETWEEN :f AND :t']; $p = ['f' => $f['from'], 't' => $f['to']];
if ($f['q'] !== '') { $w[] = '(a.summary LIKE :q OR a.actor_name LIKE :q OR a.action LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['module']) { $w[] = 'a.module = :m'; $p['m'] = $f['module']; }
if ($f['user']) { $w[] = "a.actor_type = 'staff' AND a.actor_id = :u"; $p['u'] = $f['user']; }
if ($f['actor']) { $w[] = 'a.actor_type = :at'; $p['at'] = $f['actor']; }
if ($f['entity']) { $w[] = 'a.entity_type = :et'; $p['et'] = $f['entity']; } if ($f['entity_id']) { $w[] = 'a.entity_id = :eid'; $p['eid'] = $f['entity_id']; }
$sql = "FROM sys_audit_log a WHERE " . implode(' AND ', $w);
if (($_GET['export'] ?? '') === 'csv') csv_download('audit-' . date('Ymd') . '.csv', ['When', 'Who', 'Type', 'Module', 'Action', 'Entity', 'Entity ID', 'Summary', 'IP'], all("SELECT a.created_at, a.actor_name, a.actor_type, a.module, a.action, a.entity_type, a.entity_id, a.summary, a.ip_address $sql ORDER BY a.id DESC LIMIT 20000", $p));
[$lim, $off, $pager] = ui_paginate((int)val("SELECT COUNT(*) $sql", $p), 60);
$rows = all("SELECT a.id, a.created_at, a.actor_type, a.actor_name, a.module, a.action, a.entity_type, a.entity_id, a.summary, a.ip_address, (a.old_values IS NOT NULL OR a.new_values IS NOT NULL) AS has_diff $sql ORDER BY a.id DESC LIMIT $lim OFFSET $off", $p);
$mods = col("SELECT DISTINCT module FROM sys_audit_log ORDER BY module");
$actions = '<a class="vk-btn-ghost" href="' . e('?' . http_build_query($_GET + ['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i>Export</a>';
?>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Summary, person, action"'), 'w-60') ?>
  <?= ui_field('Module', ui_select('module', ui_options($mods, '', '', $f['module'], 'Any')), 'w-36') ?>
  <?= ui_field('Staff', ui_select('user', ui_options(staff_for_select(), 'id', 'name', $f['user'], 'Anyone')), 'w-44') ?>
  <?= ui_field('Actor', ui_select('actor', ui_kv_options(['staff' => 'Staff', 'portal' => 'Portal users', 'system' => 'System / cron', 'api' => 'API / webhooks'], $f['actor'], 'Any')), 'w-36') ?>
  <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>When</th><th>Who</th><th>Module / action</th><th>What</th><th>IP</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $a): ?><tr><td class="text-xs whitespace-nowrap"><?= fdatetime(substr($a['created_at'], 0, 19)) ?></td>
    <td class="text-sm"><?= e($a['actor_name'] ?: ucfirst($a['actor_type'])) ?><span class="block text-[11px] text-muted"><?= e($a['actor_type']) ?></span></td>
    <td class="text-xs"><b><?= e($a['module']) ?></b> · <?= e($a['action']) ?><span class="block text-muted"><?= e($a['entity_type']) ?><?= $a['entity_id'] ? ' #' . (int)$a['entity_id'] : '' ?></span></td>
    <td class="text-sm"><?= e($a['summary']) ?></td><td class="text-xs text-muted"><?= e($a['ip_address']) ?></td>
    <td><?= $a['has_diff'] ? '<button class="vk-btn-ghost !py-1 text-xs diff" data-id="' . $a['id'] . '">Changes</button>' : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$rows) echo '<tr><td colspan="6">' . ui_empty('fa-clock-rotate-left', 'No activity in this range') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
<?php ob_start(); ?>
<script>
$('.diff').on('click', function () { VK.api('admin/audit-entry', { id: $(this).data('id') }).then(r => { const a = r.a, o = JSON.parse(a.old_values || '{}') || {}, n = JSON.parse(a.new_values || '{}') || {};
  const keys = [...new Set([...Object.keys(o), ...Object.keys(n)])].filter(k => JSON.stringify(o[k]) !== JSON.stringify(n[k]));
  const rows = keys.map(k => `<tr><td class="font-semibold">${VK.esc(k)}</td><td class="text-rose-600"><s>${VK.esc(typeof o[k] === 'object' ? JSON.stringify(o[k]) : (o[k] ?? ''))}</s></td><td class="text-emerald-700">${VK.esc(typeof n[k] === 'object' ? JSON.stringify(n[k]) : (n[k] ?? ''))}</td></tr>`).join('');
  Swal.fire({ title: VK.esc(a.summary || 'Changes'), width: 820, showCloseButton: true, showConfirmButton: false, html: `<div class="text-left max-h-[60vh] overflow-auto"><table class="vk-table text-xs"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>${rows || '<tr><td colspan=3>No field-level differences</td></tr>'}</tbody></table></div>` }); }); });
</script>
<?php page_script(ob_get_clean());
