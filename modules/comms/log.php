<?php
$title = 'Message log';
$subtitle = 'Every email, SMS and portal message the system sent';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'channel' => (string)($_GET['channel'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'from' => (string)($_GET['from'] ?? date('Y-m-d', strtotime('-30 days'))), 'to' => (string)($_GET['to'] ?? date('Y-m-d'))];
$w = ['DATE(m.created_at) BETWEEN :f AND :t']; $p = ['f' => $f['from'], 't' => $f['to']];
if ($f['q'] !== '') { $w[] = '(m.to_address LIKE :q OR m.subject LIKE :q OR pt.display_name LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['channel']) { $w[] = 'm.channel = :c'; $p['c'] = $f['channel']; }
if ($f['status']) { $w[] = 'm.status = :s'; $p['s'] = $f['status']; }
$sql = "FROM com_messages m LEFT JOIN prpt_parties pt ON pt.id = m.party_id WHERE " . implode(' AND ', $w);
[$lim, $off, $pager] = ui_paginate((int)val("SELECT COUNT(*) $sql", $p), 50);
$rows = all("SELECT m.id, m.channel, m.to_address, m.subject, LEFT(m.body, 400) AS body, m.status, m.error, m.related_type, m.created_at, m.sent_at, pt.display_name $sql ORDER BY m.id DESC LIMIT $lim OFFSET $off", $p);
$k = []; foreach (all("SELECT m.status, COUNT(*) n $sql GROUP BY m.status", $p) as $r) $k[$r['status']] = $r['n'];
?>
<div class="flex flex-wrap gap-2 mb-4"><?php foreach ($k as $s => $n) echo pill($s, ucfirst($s) . ' · ' . num($n)); ?></div>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Recipient, subject, name"'), 'w-60') ?>
  <?= ui_field('Channel', ui_select('channel', ui_kv_options(['email' => 'Email', 'sms' => 'SMS', 'portal' => 'Portal', 'whatsapp' => 'WhatsApp'], $f['channel'], 'Any')), 'w-36') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['sent' => 'Sent', 'delivered' => 'Delivered', 'simulated' => 'Simulated', 'failed' => 'Failed', 'queued' => 'Queued'], $f['status'], 'Any')), 'w-36') ?>
  <?= ui_field('From', ui_input('from', $f['from'], 'date'), 'w-40') ?><?= ui_field('To', ui_input('to', $f['to'], 'date'), 'w-40') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>When</th><th>Channel</th><th>Recipient</th><th>Message</th><th>Related</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $m): ?><tr>
    <td class="text-xs"><?= fdatetime($m['created_at']) ?></td><td><i class="fa-solid <?= ['email' => 'fa-envelope', 'sms' => 'fa-comment-sms', 'portal' => 'fa-mobile-screen', 'whatsapp' => 'fa-whatsapp'][$m['channel']] ?? 'fa-message' ?> text-muted"></i> <?= e($m['channel']) ?></td>
    <td class="text-sm"><?= e($m['display_name'] ?? '') ?><span class="block text-xs text-muted"><?= e($m['to_address']) ?></span></td>
    <td class="text-sm"><b><?= e($m['subject'] ?: '') ?></b><span class="block text-xs text-muted"><?= e(excerpt(strip_tags((string)$m['body']), 90)) ?></span></td>
    <td class="text-xs"><?= e($m['related_type'] ?? '') ?></td><td><?= pill($m['status']) ?><?= $m['error'] ? '<span class="block text-[11px] text-rose-600">' . e(excerpt($m['error'], 50)) . '</span>' : '' ?></td>
    <td class="text-right whitespace-nowrap"><button class="vk-icon-btn !h-8 !w-8 view-msg" data-id="<?= $m['id'] ?>"><i class="fa-solid fa-eye text-xs"></i></button><?php if ($m['status'] === 'failed' && can('comms.send')): ?><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'comms/retry-message', 'id' => $m['id']])) ?>" title="Retry"><i class="fa-solid fa-rotate-right text-xs"></i></button><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="7">' . ui_empty('fa-envelope', 'No messages') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
<?php page_script('<script>$(".view-msg").on("click",function(){VK.api("comms/message",{id:$(this).data("id")}).then(r=>{const m=r.m;Swal.fire({title:VK.esc(m.subject||m.channel.toUpperCase()),width:720,showCloseButton:true,showConfirmButton:false,html:`<p class="text-xs text-left text-muted mb-2">To ${VK.esc(m.to_address)} · ${VK.esc(m.status)}</p><div class="text-left text-sm max-h-[60vh] overflow-y-auto border rounded-xl p-3">${m.channel==="sms"?VK.esc(m.body):m.body}</div>`})})})</script>');
