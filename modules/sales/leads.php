<?php
require_once __DIR__ . '/_partials.php';
$title = 'Leads';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'stage_id' => (int)($_GET['stage_id'] ?? 0), 'source' => (string)($_GET['source'] ?? ''), 'agent' => (int)($_GET['agent'] ?? 0), 'due' => !empty($_GET['due'])];
$w = ['1=1']; $p = [];
if ($f['q'] !== '') { $w[] = '(l.full_name LIKE :q OR l.phone LIKE :q OR l.email LIKE :q OR l.lead_no LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['stage_id']) { $w[] = 'l.stage_id = :s'; $p['s'] = $f['stage_id']; }
if ($f['source']) { $w[] = 'l.source = :src'; $p['src'] = $f['source']; }
if ($f['agent']) { $w[] = 'l.assigned_to = :a'; $p['a'] = $f['agent']; }
if ($f['due']) $w[] = 'l.next_follow_up_at <= NOW()';
$sql = "FROM sls_leads l JOIN sls_stages s ON s.id = l.stage_id LEFT JOIN prpt_units u ON u.id = l.unit_id LEFT JOIN prpt_properties pr ON pr.id = l.property_id LEFT JOIN sys_users su ON su.id = l.assigned_to WHERE " . implode(' AND ', $w);
[$lim, $off, $pager] = ui_paginate((int)val("SELECT COUNT(*) $sql", $p), 50);
$rows = all("SELECT l.*, s.name AS stage, s.color_hex, u.unit_no, pr.name AS property, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS agent, (SELECT MAX(created_at) FROM sls_activities a WHERE a.lead_id = l.id) AS last_touch $sql ORDER BY l.created_at DESC LIMIT $lim OFFSET $off", $p);
$k = one("SELECT COUNT(*) n, SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) new30, SUM(next_follow_up_at <= NOW()) due, SUM(stage_id IN (SELECT id FROM sls_stages WHERE is_won = 1)) won FROM sls_leads");
$srcs = ['walk_in' => 'Walk-in', 'referral' => 'Referral', 'vikao_ecommerce' => 'vikao.io', 'website' => 'Website', 'social' => 'Social', 'call' => 'Call', 'event' => 'Event', 'agent' => 'Agent', 'other' => 'Other'];
$actions = '<button class="vk-btn-accent" data-crud-new="#m-lead" data-title="New lead"><i class="fa-solid fa-user-plus"></i>New lead</button>';
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5"><?= ui_stat('fa-users', 'All leads', num($k['n']), 'blue') ?><?= ui_stat('fa-seedling', 'New in 30 days', num($k['new30']), 'accent') ?><?= ui_stat('fa-bell', 'Follow-ups due', num($k['due']), 'red', null, page('sales/leads', ['due' => 1])) ?><?= ui_stat('fa-trophy', 'Won', num($k['won']), 'green') ?></div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Name, phone, email"'), 'w-56') ?>
  <?= ui_field('Stage', ui_select('stage_id', ui_options(all("SELECT id, name FROM sls_stages ORDER BY sort_order"), 'id', 'name', $f['stage_id'], 'Any')), 'w-40') ?>
  <?= ui_field('Source', ui_select('source', ui_kv_options($srcs, $f['source'], 'Any')), 'w-36') ?>
  <?= ui_field('Agent', ui_select('agent', ui_options(staff_for_select(), 'id', 'name', $f['agent'], 'Anyone')), 'w-44') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Lead</th><th>Interest</th><th>Source</th><th>Stage</th><th>Agent</th><th>Next follow-up</th><th>Last touch</th></tr></thead><tbody>
  <?php foreach ($rows as $l): ?><tr data-href="<?= e(page('sales/lead', ['id' => $l['id']])) ?>">
    <td><b><?= e($l['full_name']) ?></b><span class="block text-xs text-muted"><?= e($l['lead_no']) ?> · <?= e($l['phone'] ?: $l['email']) ?></span></td>
    <td class="text-sm"><?= e(ucfirst(str_replace('_', ' ', $l['interest']))) ?><span class="block text-xs text-muted"><?= e($l['unit_no'] ?: $l['property'] ?: '') ?><?= $l['budget_max'] ? ' · ≤ ' . money($l['budget_max'], true, true) : '' ?></span></td>
    <td class="text-xs"><?= e($srcs[$l['source']] ?? $l['source']) ?><?= $l['ecom_order_number'] ? '<span class="block text-accent">' . e($l['ecom_order_number']) . '</span>' : '' ?></td>
    <td><span class="inline-flex items-center gap-1.5 text-sm"><span class="h-2 w-2 rounded-full" style="background:<?= e($l['color_hex']) ?>"></span><?= e($l['stage']) ?></span></td>
    <td class="text-sm"><?= e($l['agent']) ?></td>
    <td class="text-xs <?= $l['next_follow_up_at'] && $l['next_follow_up_at'] < date('Y-m-d H:i:s') ? 'text-rose-600 font-bold' : '' ?>"><?= $l['next_follow_up_at'] ? fdatetime($l['next_follow_up_at']) : '—' ?></td>
    <td class="text-xs text-muted"><?= time_ago($l['last_touch']) ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="7">' . ui_empty('fa-user-plus', 'No leads') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
<?= lead_modal_html() ?>
