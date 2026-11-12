<?php
$title = 'Campaigns';
$subtitle = 'Bulk emails, SMS and portal announcements';
$rows = all("SELECT c.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS by_name FROM com_campaigns c LEFT JOIN sys_users u ON u.id = c.created_by ORDER BY COALESCE(c.sent_at, c.scheduled_at, c.created_at) DESC LIMIT 200");
$actions = '<a class="vk-btn-accent" href="' . e(page('comms/compose')) . '"><i class="fa-solid fa-pen-to-square"></i>New message</a>';
$audL = ['all' => 'Everyone', 'tenants' => 'Tenants', 'owners' => 'Owners', 'arrears' => 'In arrears', 'lease_expiring' => 'Leases expiring', 'custom' => 'Selected people'];
?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Campaign</th><th>Audience</th><th>Channel</th><th class="num">Recipients</th><th class="num">Sent</th><th>Status</th><th>When</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $c): $a = json_decode((string)$c['audience'], true) ?: []; ?><tr data-href="<?= e(page('comms/campaign', ['id' => $c['id']])) ?>">
    <td><b><?= e($c['name']) ?></b><span class="block text-xs text-muted"><?= e(excerpt(strip_tags((string)$c['body']), 70)) ?></span></td>
    <td class="text-sm"><?= e($audL[$a['type'] ?? 'all'] ?? '') ?><?= !empty($a['property_ids']) ? '<span class="block text-[11px] text-muted">' . count($a['property_ids']) . ' propert' . (count($a['property_ids']) > 1 ? 'ies' : 'y') . '</span>' : '' ?></td>
    <td><?= e(strtoupper($c['channel'])) ?></td><td class="num"><?= (int)$c['recipients_count'] ?></td><td class="num"><?= (int)$c['sent_count'] ?><?= $c['failed_count'] ? '<span class="block text-[11px] text-rose-600">' . (int)$c['failed_count'] . ' no contact</span>' : '' ?></td>
    <td><?= pill($c['status']) ?></td><td class="text-xs"><?= fdatetime($c['sent_at'] ?? $c['scheduled_at'] ?? $c['created_at']) ?><span class="block text-muted"><?= e($c['by_name']) ?></span></td>
    <td class="text-right whitespace-nowrap"><?php if (in_array($c['status'], ['draft', 'scheduled'], true)): ?><button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'comms/send-campaign', 'id' => $c['id']])) ?>" data-confirm="Send now?" data-icon="question">Send now</button><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'comms/cancel-campaign', 'id' => $c['id']])) ?>" data-confirm="Cancel this campaign?"><i class="fa-solid fa-ban text-xs"></i></button><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="8">' . ui_empty('fa-bullhorn', 'No campaigns yet') . '</td></tr>'; ?>
  </tbody></table></div></div>
