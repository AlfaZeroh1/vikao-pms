<?php
$title = 'Post official listing';
$subtitle = 'Publish a managed unit on vikao.io with the Official badge — goes live immediately, no review';
if (!(require __DIR__ . '/_guard.php')) return;
$unitId = (int)($_GET['unit_id'] ?? 0);
$u = $unitId ? one("SELECT u.*, pr.name AS property, pr.address, pr.area, pr.ecom_location_id, pr.description AS pdesc FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.id = ?", [$unitId]) : null;
$cats = eall("SELECT slug, CONCAT(name, ' (', family, ' · ', deal_type, ')') AS name, deal_type FROM categories WHERE is_postable = 1 AND is_active = 1 AND family IN ('residential','commercial','land','short_stay') ORDER BY family, sort_order, name");
$loc = $u && $u['ecom_location_id'] ? eone("SELECT id, full_name FROM locations WHERE id = ?", [$u['ecom_location_id']]) : null;
$photos = $u ? all("SELECT title, file_path FROM prpt_documents WHERE ((owner_type = 'unit' AND owner_id = ?) OR (owner_type = 'property' AND owner_id = ?)) AND doc_type = 'photo' AND file_path IS NOT NULL", [$u['id'], $u['property_id']]) : [];
$amen = $u ? col("SELECT name FROM prpt_amenities WHERE property_id = ?", [$u['property_id']]) : [];
$forSale = $u && in_array($u['purpose'], ['sale', 'rent_and_sale'], true);
$defTitle = $u ? trim(($u['bedrooms'] ? $u['bedrooms'] . ' bedroom ' : '') . str_replace('_', ' ', $u['unit_type']) . ' for ' . ($forSale ? 'sale' : 'rent') . ' at ' . $u['property'] . ', ' . $u['area']) : '';
$defDesc = $u ? '<p>' . e(ucfirst($defTitle)) . '.</p>' . ($u['pdesc'] ? '<p>' . e($u['pdesc']) . '</p>' : '') . '<ul><li>' . (int)$u['bedrooms'] . ' bedrooms · ' . (int)$u['bathrooms'] . ' bathrooms' . ($u['size_sqm'] ? ' · ' . num($u['size_sqm']) . ' m²' : '') . '</li>' . ($amen ? '<li>Amenities: ' . e(implode(', ', $amen)) . '</li>' : '') . '<li>Professionally managed by Vikao — verified title and transparent billing.</li></ul>' : '';
?>
<?php if (!$u): ?>
<div class="vk-panel p-6 max-w-xl"><span class="vk-label">Which unit?</span><?= ui_unit_picker('unit_pick', null, 'id="unit-pick"') ?><p class="text-xs text-muted mt-2">Units for sale or rent from any managed property.</p></div>
<?php page_script('<script>$("#unit-pick").on("change",function(){if(this.value)location.href=VK.url("index.php",{r:"ecommerce/push",unit_id:this.value})})</script>'); return; endif; ?>
<?php if ($u['ecom_listing_uuid']): ?><div class="vk-panel p-4 mb-5 text-sm flex items-center gap-3"><i class="fa-solid fa-circle-info text-accent"></i>This unit is already published. <a class="font-bold text-accent" target="_blank" href="<?= e(ecom_url('ad.php?id=' . $u['ecom_listing_uuid'])) ?>">View the ad</a> — publishing again creates a second ad.</div><?php endif; ?>
<form data-ajax="<?= e(url('api.php', ['r' => 'ecommerce/push-unit'])) ?>" enctype="multipart/form-data" class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-6 items-start" id="push">
  <input type="hidden" name="unit_id" value="<?= $u['id'] ?>">
  <div class="space-y-5">
    <section class="vk-panel p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
      <?= ui_field('Title * <span class="font-normal text-muted">(10+ characters)</span>', ui_input('title', ucfirst($defTitle), 'text', 'required maxlength="120"'), 'sm:col-span-2') ?>
      <?= ui_field('Marketplace category *', ui_select('category_slug', ui_options($cats, 'slug', 'name', null, 'Choose…'), 'required id="cat"')) ?>
      <div><span class="vk-label">Location on vikao.io *</span><input type="hidden" name="ecom_location_id" id="loc-id" value="<?= (int)($loc['id'] ?? 0) ?: '' ?>"><div class="relative"><input class="vk-input" id="loc-q" autocomplete="off" placeholder="Search county, town or estate…" value="<?= e($loc['full_name'] ?? '') ?>"><div class="select-search-results hidden" id="loc-res"></div></div></div>
      <?= ui_field('Price (KSh) *', ui_input('price', $forSale ? ($u['sale_price'] ?: '') : ($u['rent_amount'] ?: ''), 'number', 'required step="any"')) ?>
      <?= ui_field('Reservation fee (paid on vikao.io)', ui_input('reservation_fee', $forSale ? 50000 : ($u['rent_amount'] ?: 0), 'number', 'step="any"')) ?>
      <?= ui_field('Billing period (rentals)', ui_select('billing_period', ui_kv_options(['month' => 'per month', 'year' => 'per year', 'night' => 'per night', 'week' => 'per week']))) ?>
    </section>
    <section class="vk-panel p-5"><span class="vk-label">Description *</span><textarea name="description_html" rows="9" class="vk-textarea" required><?= e($defDesc) ?></textarea><p class="text-xs text-muted mt-1">Basic HTML allowed (&lt;p&gt;, &lt;ul&gt;, &lt;b&gt;).</p></section>
    <section class="vk-panel p-5">
      <div class="flex items-center mb-3"><span class="vk-label !mb-0">Photos * <span class="font-normal text-muted">(at least 6 — the first is the cover)</span></span><b class="ml-auto text-sm" id="ph-count">0 / 6</b></div>
      <?php if ($photos): ?><div class="grid grid-cols-3 sm:grid-cols-6 gap-2 mb-3"><?php foreach ($photos as $p): ?><label class="relative block aspect-square rounded-xl overflow-hidden border border-line cursor-pointer"><img src="<?= e(file_url($p['file_path'])) ?>" class="h-full w-full object-cover" alt=""><input type="checkbox" name="existing_photos[]" value="<?= e($p['file_path']) ?>" class="ex-ph absolute top-1.5 left-1.5 accent-amber-600" checked></label><?php endforeach; ?></div><?php endif; ?>
      <input type="file" name="photos[]" id="ph-in" accept="image/jpeg,image/png,image/webp" multiple class="vk-input" data-field="photos">
      <div id="ph-prev" class="grid grid-cols-3 sm:grid-cols-6 gap-2 mt-3"></div>
    </section>
    <section class="vk-panel p-5"><div class="flex items-center mb-3"><span class="vk-label !mb-0">Payment packages <span class="font-normal text-muted">(buyers pick one when reserving)</span></span><button type="button" class="ml-auto vk-btn-ghost !py-1.5 text-xs" id="add-pk"><i class="fa-solid fa-plus"></i>Package</button></div><div id="pks" class="space-y-2"></div></section>
  </div>
  <aside class="vk-panel p-5 xl:sticky xl:top-20 space-y-3">
    <p class="font-bold"><?= e($u['unit_no']) ?> · <?= e($u['property']) ?></p>
    <p class="text-sm text-muted"><?= e(ucwords(str_replace('_', ' ', $u['unit_type']))) ?> · <?= (int)$u['bedrooms'] ?> bd · <?= e(str_replace('_', ' ', $u['purpose'])) ?> · <?= pill($u['status']) ?></p>
    <ul class="text-xs text-muted space-y-1"><li><i class="fa-solid fa-crown text-amber-500"></i> Official badge &amp; premium placement</li><li><i class="fa-solid fa-bolt text-amber-500"></i> Live instantly (no moderation queue)</li><li><i class="fa-solid fa-link text-amber-500"></i> Reservations flow back as sales leads</li></ul>
    <button class="vk-btn-accent w-full justify-center"><i class="fa-solid fa-rocket"></i>Publish to vikao.io</button>
  </aside>
</form>
<?php ob_start(); ?>
<script>
(function () {
  let n = 0;
  function pk(d) { d = d || {}; const i = n++; $('#pks').append(`<div class="grid grid-cols-1 sm:grid-cols-[1.2fr_1fr_1fr_1fr_auto] gap-2 items-start rounded-xl border border-line p-2"><input name="packages[${i}][name]" class="vk-input !py-1.5" placeholder="Name e.g. Cash buyer" value="${VK.esc(d.name || '')}">
    <select name="packages[${i}][package_type]" class="vk-select !py-1.5">${['outright', 'installment', 'lease', 'short_stay', 'custom'].map(x => `<option ${x === d.t ? 'selected' : ''}>${x}</option>`).join('')}</select>
    <input name="packages[${i}][total_price]" class="vk-input !py-1.5" type="number" placeholder="Total price" value="${d.total || ''}"><input name="packages[${i}][deposit_amount]" class="vk-input !py-1.5" type="number" placeholder="Deposit" value="${d.dep || ''}">
    <label class="flex items-center gap-1 text-xs pt-2"><input type="checkbox" name="packages[${i}][is_recommended]" value="1" ${d.rec ? 'checked' : ''}>★<button type="button" class="ml-1 text-muted" onclick="$(this).closest('.grid').remove()"><i class="fa-solid fa-xmark"></i></button></label>
    <input name="packages[${i}][description]" class="vk-input !py-1.5 sm:col-span-5" placeholder="What's included" value="${VK.esc(d.desc || '')}"></div>`); }
  $('#add-pk').on('click', () => pk());
  const price = +$('[name=price]').val() || 0;
  <?php if ($forSale): ?>pk({ name: 'Cash purchase', t: 'outright', total: price, dep: Math.round(price * 0.1), rec: 1, desc: '10% deposit, balance on transfer' }); pk({ name: '24-month instalments', t: 'installment', total: Math.round(price * 1.12), dep: Math.round(price * 0.3), desc: '30% deposit, balance over 24 months' });<?php else: ?>pk({ name: 'Standard lease', t: 'lease', total: price, dep: price * <?= (float)($u['deposit_months'] ?: 1) ?>, rec: 1, desc: '12-month lease, deposit refundable' });<?php endif; ?>
  function count() { const c = $('.ex-ph:checked').length + ($('#ph-in')[0].files || []).length; $('#ph-count').text(c + ' / 6').toggleClass('text-rose-600', c < 6).toggleClass('text-emerald-600', c >= 6); }
  $('#ph-in').on('change', function () { $('#ph-prev').html(''); [...this.files].forEach(f => { const r = new FileReader(); r.onload = e => $('#ph-prev').append(`<img src="${e.target.result}" class="aspect-square w-full object-cover rounded-xl border border-line">`); r.readAsDataURL(f); }); count(); });
  $(document).on('change', '.ex-ph', count); count();
  let t; $('#loc-q').on('input focus', function () { clearTimeout(t); const q = this.value; t = setTimeout(() => $.get(VK.url('api.php', { r: 'ecommerce/locations', q })).then(r => $('#loc-res').html((r.items || []).map(i => `<button type="button" data-id="${i.id}"><b>${VK.esc(i.label)}</b></button>`).join('') || '<p class="p-3 text-sm text-muted">No match</p>').removeClass('hidden')), 200); })
    .on('blur', () => setTimeout(() => $('#loc-res').addClass('hidden'), 150));
  $('#loc-res').on('mousedown', 'button', function () { $('#loc-id').val($(this).data('id')); $('#loc-q').val($(this).text()); $('#loc-res').addClass('hidden'); });
  $('#push').on('submit', function (e) { if ($('.ex-ph:checked').length + ($('#ph-in')[0].files || []).length < 6) { e.preventDefault(); e.stopImmediatePropagation(); VK.toast('Add at least 6 photos', 'warning'); } if (!$('#loc-id').val()) { e.preventDefault(); e.stopImmediatePropagation(); VK.toast('Choose the location', 'warning'); } });
  $('#push').on('vk:saved', (e, r) => { if (r.url) window.open(r.url, '_blank'); });
})();
</script>
<?php page_script(ob_get_clean());
