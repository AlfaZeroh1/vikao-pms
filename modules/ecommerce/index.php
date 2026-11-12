<?php
$title = 'Marketplace overview';
$subtitle = 'vikao.io — ads, accounts, reservations and official listings, managed from the PMS';
if (!(require __DIR__ . '/_guard.php')) return;
$s = ecom_stats();
$pending = $s['listings_pending'] ?? 0;
$events = eall("SELECT * FROM pms_events WHERE status <> 'acknowledged' ORDER BY id DESC LIMIT 10");
$recent = eall("SELECT o.order_number, o.amount_paid, o.status, o.created_at, o.contact_name, JSON_UNQUOTE(JSON_EXTRACT(o.listing_snapshot, '$.title')) AS title FROM orders o ORDER BY o.id DESC LIMIT 8");
$daily = eall("SELECT DATE(created_at) d, COUNT(*) n FROM listings WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY d ORDER BY d");
$actions = '<a class="vk-btn-ghost" target="_blank" href="' . e(ecom_url()) . '"><i class="fa-solid fa-arrow-up-right-from-square"></i>Open vikao.io</a>' . (can('ecommerce.official') ? '<a class="vk-btn-accent" href="' . e(page('ecommerce/push')) . '"><i class="fa-solid fa-crown"></i>Post official listing</a>' : '');
?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
  <?= ui_stat('fa-hourglass-half', 'Ads awaiting review', num($pending), $pending ? 'red' : 'slate', null, page('ecommerce/listings', ['status' => 'pending'])) ?>
  <?= ui_stat('fa-rectangle-ad', 'Live ads', num($s['listings_active'] ?? 0), 'green', num($s['official_active']) . ' official', page('ecommerce/listings', ['status' => 'active'])) ?>
  <?= ui_stat('fa-users', 'Accounts', num($s['users']), 'blue', '+' . num($s['users_new_30d']) . ' in 30 d', page('ecommerce/accounts')) ?>
  <?= ui_stat('fa-sack-dollar', 'Reservation revenue', money($s['reservation_revenue'], true, true), 'accent', num($s['orders_paid']) . ' paid orders', page('ecommerce/orders')) ?>
  <?= ui_stat('fa-eye', 'Ad views', num($s['views_total']), 'violet') ?>
  <?= ui_stat('fa-comments', 'Chats (30 d)', num($s['chats_30d']), 'blue') ?>
  <?= ui_stat('fa-flag', 'Open reports', num($s['open_reports']), $s['open_reports'] ? 'red' : 'slate', null, page('ecommerce/listings', ['status' => 'active', 'reported' => 1])) ?>
  <?= ui_stat('fa-bell', 'Events to acknowledge', num(count($events)), count($events) ? 'accent' : 'slate', null, page('ecommerce/orders', ['tab' => 'events'])) ?>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="vk-panel p-5 lg:col-span-2"><h3 class="font-bold mb-3">New ads — last 30 days</h3><div class="h-56"><canvas id="c-ads"></canvas></div></div>
  <div class="vk-panel p-5"><h3 class="font-bold mb-3">Live ads by type</h3><div class="h-56"><canvas id="c-fam"></canvas></div></div>
  <div class="vk-panel overflow-hidden lg:col-span-2"><div class="px-5 py-3.5 border-b border-line font-bold">Latest reservations</div><table class="vk-table"><tbody>
    <?php foreach ($recent as $o): ?><tr><td><b><?= e($o['order_number']) ?></b><span class="block text-xs text-muted"><?= e($o['contact_name']) ?> · <?= time_ago($o['created_at']) ?></span></td><td class="text-sm"><?= e($o['title']) ?></td><td><?= pill($o['status']) ?></td><td class="num font-bold"><?= money($o['amount_paid'], false) ?></td></tr><?php endforeach; ?>
  </tbody></table><?= $recent ? '' : ui_empty('fa-bag-shopping', 'No reservations yet') ?></div>
  <div class="vk-panel p-5"><h3 class="font-bold mb-3">Members by plan</h3><?php foreach ($s['tiers'] as $t): ?><div class="flex justify-between text-sm py-1"><span><?= e($t['name']) ?></span><b><?= num($t['n']) ?></b></div><?php endforeach; ?></div>
</div>
<?php page_script('<script>VK.chart("c-ads",{type:"line",data:{labels:' . json_encode(array_map(fn($r) => fdate($r['d'], 'j M'), $daily)) . ',datasets:[{data:' . json_encode(array_map(fn($r) => (int)$r['n'], $daily)) . ',borderColor:"#D97706",backgroundColor:"rgba(217,119,6,.12)",fill:true,tension:.35}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}}}});VK.chart("c-fam",{type:"doughnut",data:{labels:' . json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['family'])), $s['by_family'])) . ',datasets:[{data:' . json_encode(array_map(fn($r) => (int)$r['n'], $s['by_family'])) . ',backgroundColor:["#D97706","#0EA5E9","#10B981","#8B5CF6","#F43F5E"]}]},options:{maintainAspectRatio:false,plugins:{legend:{position:"bottom"}}}})</script>');
