<?php
$title = 'Ad moderation';
$subtitle = 'Approve or decline ads submitted on vikao.io, promote, pause or close them';
if (!(require __DIR__ . '/_guard.php')) return;
$f = ['status' => (string)($_GET['status'] ?? 'pending'), 'family' => (string)($_GET['family'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'official' => !empty($_GET['official'])];
if ($f['status'] === 'all') $f['status'] = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$res = ecom_listings($f, 24, ($page - 1) * 24);
if (!empty($_GET['reported'])) $res['rows'] = array_values(array_filter($res['rows'], fn($r) => $r['reports'] > 0));
[$lim, $off, $pager] = ui_paginate($res['total'], 24);
$counts = []; foreach (eall("SELECT status, COUNT(*) n FROM listings WHERE deleted_at IS NULL GROUP BY status") as $r) $counts[$r['status']] = $r['n'];
$fam = ['residential' => 'Residential', 'commercial' => 'Commercial', 'land' => 'Land', 'short_stay' => 'Short stay', 'service' => 'Services'];
?>
<div class="flex flex-wrap gap-2 mb-4"><?php foreach (['pending' => 'Awaiting review', 'active' => 'Live', 'rejected' => 'Declined', 'paused' => 'Paused', 'draft' => 'Drafts', 'expired' => 'Expired', 'closed' => 'Closed', 'sold' => 'Sold', '' => 'All'] as $k => $l): ?><a class="vk-chip <?= $f['status'] === $k ? '!border-accent !bg-accent/10' : '' ?>" href="<?= e(page('ecommerce/listings', array_filter(['status' => $k ?: 'all', 'family' => $f['family']]))) ?>"><?= $l ?> <b><?= $k ? (int)($counts[$k] ?? 0) : array_sum($counts) ?></b></a><?php endforeach; ?></div>
<?= ui_filter_open() ?>
  <input type="hidden" name="status" value="<?= e($f['status']) ?>">
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Title, ref code, seller"'), 'w-64') ?>
  <?= ui_field('Type', ui_select('family', ui_kv_options($fam, $f['family'], 'All')), 'w-40') ?>
  <label class="vk-switch pb-3"><input type="checkbox" name="official" value="1" <?= $f['official'] ? 'checked' : '' ?>>Official only</label>
<?= ui_filter_close() ?>
<?php if ($f['status'] === 'pending' && $res['rows'] && can('ecommerce.moderate')): ?><div class="vk-panel p-3 mb-4 flex flex-wrap items-center gap-2 text-sm"><span class="text-muted"><b id="sel-count">0</b> selected</span><button class="vk-btn-dark !py-1.5 text-xs" id="bulk-ok"><i class="fa-solid fa-check"></i>Approve selected</button><button class="vk-btn-ghost !py-1.5 text-xs !text-rose-600" id="bulk-no"><i class="fa-solid fa-xmark"></i>Decline selected</button></div><?php endif; ?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
<?php foreach ($res['rows'] as $l): $link = ecom_url('ad.php?id=' . $l['uuid']); ?>
  <div class="vk-panel overflow-hidden flex flex-col">
    <div class="relative aspect-[16/9] bg-surface2"><?php if ($l['thumb_path']): ?><img src="<?= e(ecom_media_url($l['thumb_path'])) ?>" class="h-full w-full object-cover" loading="lazy" alt=""><?php endif; ?>
      <div class="absolute top-2 left-2 flex gap-1"><?= pill($l['status']) ?><?= $l['is_official'] ? pill('active', 'Official', 'amber') : '' ?><?= $l['is_premium'] ? pill('active', 'Premium', 'violet') : '' ?></div>
      <?php if ($f['status'] === 'pending'): ?><label class="absolute top-2 right-2 grid h-8 w-8 place-items-center rounded-lg bg-white/90"><input type="checkbox" class="row-sel accent-amber-600" value="<?= $l['id'] ?>"></label><?php endif; ?>
      <span class="absolute bottom-2 right-2 rounded-md bg-black/60 px-2 py-0.5 text-[11px] text-white"><i class="fa-solid fa-images"></i> <?= (int)$l['photos'] ?></span></div>
    <div class="p-4 flex-1 flex flex-col">
      <p class="text-[11px] text-muted"><?= e($l['ref_code']) ?> · <?= e($l['category']) ?> · <?= e($l['submission_type'] === 'edit' ? 'edited ad' : 'new ad') ?></p>
      <a href="<?= e($link) ?>" target="_blank" class="font-bold leading-snug hover:text-accent line-clamp-2"><?= e($l['title']) ?></a>
      <p class="text-sm font-extrabold mt-1"><?= money($l['price']) ?><?= $l['billing_period'] && $l['billing_period'] !== 'once' ? '<span class="text-xs font-normal text-muted"> / ' . e($l['billing_period']) . '</span>' : '' ?></p>
      <p class="text-xs text-muted mt-1"><i class="fa-solid fa-location-dot"></i> <?= e(excerpt($l['location'], 60)) ?></p>
      <p class="text-xs mt-2"><i class="fa-solid fa-user text-muted"></i> <?= e($l['seller']) ?> <span class="text-muted">· <?= e($l['seller_phone'] ?: $l['seller_email']) ?></span></p>
      <p class="text-xs text-muted mt-2 line-clamp-3"><?= e(excerpt((string)$l['description_text'], 220)) ?></p>
      <?php if ($l['reject_reason'] && $l['status'] === 'rejected'): ?><p class="text-xs text-rose-600 mt-2">Declined: <?= e($l['reject_reason']) ?></p><?php endif; ?>
      <?php if ($l['reports']): ?><p class="text-xs text-rose-600 mt-2"><i class="fa-solid fa-flag"></i> <?= (int)$l['reports'] ?> open report(s)</p><?php endif; ?>
      <p class="text-[11px] text-muted mt-auto pt-3"><i class="fa-regular fa-eye"></i> <?= num($l['views_count']) ?> · <i class="fa-regular fa-heart"></i> <?= num($l['favourites_count']) ?> · <i class="fa-regular fa-comments"></i> <?= num($l['chats_count']) ?> · <?= $l['submitted_at'] ? 'submitted ' . time_ago($l['submitted_at']) : '' ?></p>
    </div>
    <?php if (can('ecommerce.moderate')): ?><div class="px-4 py-3 border-t border-line flex flex-wrap gap-2">
      <?php if ($l['status'] === 'pending'): ?><button class="vk-btn-accent !py-1.5 text-xs flex-1 justify-center" data-action="<?= e(url('api.php', ['r' => 'ecommerce/approve', 'id' => $l['id']])) ?>"><i class="fa-solid fa-check"></i>Approve</button>
        <button class="vk-btn-ghost !py-1.5 text-xs flex-1 justify-center !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'ecommerce/reject', 'id' => $l['id']])) ?>" data-prompt="Why decline? (the seller sees this)"><i class="fa-solid fa-xmark"></i>Decline</button>
      <?php else: ?>
        <?php if ($l['status'] === 'active'): ?><button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'ecommerce/listing-status'])) ?>" data-payload='{"id":<?= $l['id'] ?>,"status":"paused"}'>Pause</button>
          <button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'ecommerce/premium'])) ?>" data-payload='{"id":<?= $l['id'] ?>,"on":<?= $l['is_premium'] ? 0 : 1 ?>}'><?= $l['is_premium'] ? 'Unpromote' : '<i class="fa-solid fa-bolt"></i>Promote' ?></button>
          <button class="vk-btn-ghost !py-1.5 text-xs !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'ecommerce/listing-status'])) ?>" data-payload='{"id":<?= $l['id'] ?>,"status":"closed"}' data-confirm="Take this ad down?">Take down</button>
        <?php elseif (in_array($l['status'], ['paused', 'closed', 'expired', 'rejected'], true)): ?><button class="vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'ecommerce/approve', 'id' => $l['id']])) ?>" data-confirm="Re-publish this ad?" data-icon="question"><i class="fa-solid fa-rotate"></i>Publish</button><?php endif; ?>
        <a class="vk-btn-ghost !py-1.5 text-xs ml-auto" target="_blank" href="<?= e($link) ?>"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>
      <?php endif; ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php if (!$res['rows']) echo '<div class="vk-panel">' . ui_empty('fa-list-check', $f['status'] === 'pending' ? 'Inbox zero — nothing to review' : 'No ads match') . '</div>'; ?>
<div class="mt-4 vk-panel"><?= $pager ?></div>
<?php page_script('<script>$(document).on("change",".row-sel",()=>$("#sel-count").text($(".row-sel:checked").length));const sel=()=>$(".row-sel:checked").map((i,x)=>x.value).get();$("#bulk-ok").on("click",()=>{const ids=sel();if(!ids.length)return VK.toast("Select ads first","warning");VK.api("ecommerce/approve",{ids}).then(r=>{VK.toast(r.message);setTimeout(()=>location.reload(),400)})});$("#bulk-no").on("click",async()=>{const ids=sel();if(!ids.length)return VK.toast("Select ads first","warning");const r=await Swal.fire({title:"Reason for declining",input:"text",showCancelButton:true,inputValidator:v=>!v&&"Required"});if(!r.isConfirmed)return;VK.api("ecommerce/reject",{ids,reason:r.value}).then(x=>{VK.toast(x.message);setTimeout(()=>location.reload(),400)})})</script>');
