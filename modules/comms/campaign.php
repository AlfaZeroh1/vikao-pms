<?php
$c = one("SELECT * FROM com_campaigns WHERE id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$c) { echo ui_empty('fa-bullhorn', 'Campaign not found'); return; }
$title = $c['name']; $subtitle = strtoupper($c['channel']) . ' · ' . pill($c['status']);
$crumbs = [['Campaigns', page('comms/campaigns')]];
$msgs = all("SELECT m.*, p.display_name FROM com_messages m LEFT JOIN prpt_parties p ON p.id = m.party_id WHERE m.campaign_id = ? ORDER BY m.id LIMIT 1000", [$c['id']]);
$by = []; foreach ($msgs as $m) $by[$m['status']] = ($by[$m['status']] ?? 0) + 1;
?>
<div class="grid grid-cols-1 lg:grid-cols-[1fr_380px] gap-6 items-start">
  <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line flex gap-2 flex-wrap"><b class="mr-2">Messages</b><?php foreach ($by as $s => $n) echo pill($s, ucfirst($s) . ' · ' . $n); ?></div>
    <div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>Recipient</th><th>Channel</th><th>To</th><th>Status</th><th>Sent</th></tr></thead><tbody>
    <?php foreach ($msgs as $m): ?><tr><td><?= e($m['display_name'] ?? '—') ?></td><td><?= e($m['channel']) ?></td><td class="text-xs"><?= e($m['to_address']) ?></td><td><?= pill($m['status']) ?><?= $m['error'] ? '<span class="block text-[11px] text-rose-600">' . e(excerpt($m['error'], 60)) . '</span>' : '' ?></td><td class="text-xs"><?= fdatetime($m['sent_at'] ?? $m['created_at']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?= $msgs ? '' : ui_empty('fa-envelope', 'Not sent yet') ?></div>
  <div class="vk-panel p-5"><p class="font-bold"><?= e($c['subject'] ?: '(no subject)') ?></p><div class="mt-3 text-sm prose max-w-none"><?= $c['channel'] === 'sms' ? nl2br(e($c['body'])) : sanitize_html((string)$c['body']) ?></div></div>
</div>
