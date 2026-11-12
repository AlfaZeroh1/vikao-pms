<?php
require_once __DIR__ . '/../property/_partials.php';
$title = 'Open a billing account';
$subtitle = 'Who lives here, who pays which bill, and when we invoice';
$crumbs = [['Accounts & leases', page('billing/leases')]];
$unitId = (int)($_GET['unit_id'] ?? 0);
$bts = all("SELECT id, name, code, billing_method, frequency, default_amount, default_unit_rate, default_bill_to, unit_label FROM cfg_bill_types WHERE is_active = 1 AND category NOT IN ('deposit','penalty','short_stay','sale') ORDER BY sort_order, name");
$templates = all("SELECT id, name FROM prpt_doc_templates WHERE doc_type = 'lease' ORDER BY is_default DESC, name");
$occ = [
    'tenant_managed' => ['fa-house-user', 'Leased — we manage the tenant', 'We know the tenant and collect rent plus every other bill.'],
    'tenant_owner_managed' => ['fa-user-tie', 'Leased — owner manages the tenant', 'Owner collects rent themselves. We bill the tenant (or owner) for service charge & utilities only.'],
    'owner_occupied' => ['fa-house-chimney-user', 'Owner occupied', 'The home owner lives here. Service charge & utilities go to the owner.'],
    'owner_vacant' => ['fa-house-lock', 'Owned — not occupied', 'Nobody lives here but the owner still pays service charge.'],
    'anonymous' => ['fa-user-secret', 'Anonymous occupant', 'Someone lives here but we don\'t know them yet — bill the unit.'],
    'company_let' => ['fa-building-user', 'Company let', 'A company leases the unit for staff.'],
];
?>
<form data-ajax="<?= e(url('api.php', ['r' => 'billing/create-lease'])) ?>" id="lease-form" class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-6 items-start">
<div class="space-y-5">
  <!-- 1 unit -->
  <section class="vk-panel p-5">
    <h3 class="font-bold mb-3"><span class="step-dot">1</span>Unit</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-start">
      <?= ui_field('Unit *', ui_unit_picker('unit_id', $unitId ?: null, 'id="ln-unit" required')) ?>
      <div id="unit-card" class="rounded-xl bg-surface2/60 p-3 text-sm hidden"></div>
    </div>
  </section>
  <!-- 2 occupancy -->
  <section class="vk-panel p-5">
    <h3 class="font-bold mb-3"><span class="step-dot">2</span>Occupancy</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <?php foreach ($occ as $k => [$ic, $l, $d]): ?>
        <label class="occ-card cursor-pointer"><input type="radio" name="occupancy_type" value="<?= $k ?>" class="peer sr-only" <?= $k === 'tenant_managed' ? 'checked' : '' ?> data-show-when>
          <span class="block h-full rounded-2xl border-2 border-line p-4 peer-checked:border-accent peer-checked:bg-accent/5 transition"><i class="fa-solid <?= $ic ?> text-accent text-lg"></i><b class="block mt-2 text-sm"><?= e($l) ?></b><span class="block text-xs text-muted mt-1"><?= e($d) ?></span></span></label>
      <?php endforeach; ?>
    </div>
  </section>
  <!-- 3 people -->
  <section class="vk-panel p-5">
    <h3 class="font-bold mb-3"><span class="step-dot">3</span>People on the account</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-when-occupancy_type="tenant_managed,tenant_owner_managed,company_let,anonymous">
      <div><?= ui_field('<span data-when-occupancy_type="tenant_managed,tenant_owner_managed,company_let">Tenant *</span><span data-when-occupancy_type="anonymous">Occupant (optional — leave blank to create a placeholder)</span>', ui_party_picker('tenant_id', null, '', 'id="ln-tenant"')) ?>
        <?php if (can('property.parties.manage')): ?><button type="button" class="mt-2 text-xs font-bold text-accent" id="new-tenant"><i class="fa-solid fa-user-plus"></i> New tenant</button><?php endif; ?></div>
      <div data-when-occupancy_type="tenant_managed,tenant_owner_managed,company_let"><?= ui_field('Guarantor (optional)', ui_party_picker('guarantor_id')) ?></div>
      <div class="sm:col-span-2" data-when-occupancy_type="tenant_managed,tenant_owner_managed,company_let"><span class="vk-label">Co-tenants</span><div id="co-list" class="space-y-2"></div><button type="button" class="mt-1 text-xs font-bold text-accent" id="add-co"><i class="fa-solid fa-plus"></i> Add co-tenant</button></div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
      <div><?= ui_field('Owner', ui_party_picker('owner_id', null, 'owner', 'id="ln-owner"')) ?><p class="text-xs text-muted mt-1">Defaults to the unit's owner on record. All co-owners are attached automatically and see this account in their portal.</p></div>
      <div id="owners-card" class="text-sm"></div>
    </div>
  </section>
  <!-- 4 bills -->
  <section class="vk-panel overflow-hidden">
    <div class="flex items-center gap-3 px-5 py-4 border-b border-line"><h3 class="font-bold"><span class="step-dot">4</span>Bills to follow</h3><span class="text-xs text-muted">Defaults come from the unit &amp; property — edit anything</span>
      <button type="button" class="ml-auto vk-btn-ghost !py-1.5 text-xs" id="reload-charges"><i class="fa-solid fa-rotate"></i>Reset defaults</button></div>
    <div class="overflow-x-auto"><table class="vk-table" id="charges"><thead><tr><th class="w-8"></th><th>Bill</th><th>Method</th><th class="w-36">Amount / rate</th><th class="w-36">Frequency</th><th class="w-56">Bill to</th></tr></thead><tbody></tbody></table></div>
    <div class="px-5 py-3 border-t border-line flex flex-wrap items-center gap-2" data-field="charges">
      <select id="add-bt" class="vk-select !w-60 !py-2"><?= ui_options($bts, 'id', 'name', null, 'Add another bill…') ?></select>
      <span class="text-xs text-muted">Metered bills are billed from approved meter readings at the rate shown (tariff tiers apply if configured).</span>
    </div>
  </section>
  <!-- 5 terms -->
  <section class="vk-panel p-5">
    <h3 class="font-bold mb-3"><span class="step-dot">5</span>Terms &amp; invoicing</h3>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <?= ui_field('Start date *', ui_input('start_date', date('Y-m-d'), 'date', 'required')) ?>
      <?= ui_field('End date <span class="text-muted font-normal">(blank = periodic)</span>', ui_input('end_date', '', 'date')) ?>
      <?= ui_field('Notice period (days)', ui_input('notice_period_days', 30, 'number')) ?>
      <?= ui_field('Invoice on day', ui_input('billing_day', 1, 'number', 'min="1" max="28"')) ?>
      <?= ui_field('Due on day', ui_input('due_day', 5, 'number', 'min="1" max="28" id="ln-due"')) ?>
      <div data-when-occupancy_type="tenant_managed,tenant_owner_managed,company_let"><?= ui_field('Deposit (KSh)', ui_input('deposit_amount', '', 'number', 'step="any" id="ln-dep"')) ?></div>
      <div data-when-occupancy_type="tenant_managed,company_let"><?= ui_field('Rent escalation %', ui_input('escalation_pct', '', 'number', 'step="0.01"')) ?></div>
      <div data-when-occupancy_type="tenant_managed,company_let"><?= ui_field('Every (months)', ui_input('escalation_every_months', '', 'number')) ?></div>
      <div data-when-occupancy_type="tenant_managed,tenant_owner_managed,company_let"><?= ui_field('Agreement template', ui_select('template_id', ui_options($templates, 'id', 'name', null, 'Default lease template'))) ?></div>
      <div class="sm:col-span-3"><?= ui_field('Notes', '<textarea name="notes" class="vk-textarea" rows="2"></textarea>') ?></div>
    </div>
    <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2">
      <label class="vk-switch"><input type="hidden" name="auto_invoice" value="0"><input type="checkbox" name="auto_invoice" value="1" checked>Invoice automatically every period</label>
      <label class="vk-switch"><input type="checkbox" name="bill_now" value="1">Raise this month's invoice now</label>
      <label class="vk-switch"><input type="checkbox" name="send_invites" value="1" checked>Email portal activation links</label>
    </div>
  </section>
</div>
<aside class="vk-panel p-5 xl:sticky xl:top-20 space-y-3">
  <h3 class="font-bold">Summary</h3>
  <div id="sum" class="text-sm space-y-1.5"><p class="text-muted">Choose a unit to begin.</p></div>
  <div class="border-t border-line pt-3 flex justify-between font-bold"><span>Monthly fixed bills</span><span id="sum-total">KSh 0.00</span></div>
  <button class="vk-btn-accent w-full justify-center"><i class="fa-solid fa-file-signature"></i>Open account</button>
  <p class="text-xs text-muted">A lease agreement is generated from the template and shared in the tenant's portal. Deposits are invoiced and tracked separately.</p>
</aside>
</form>
<?php if (can('property.parties.manage')) echo party_modal_html(); ?>
<style>.step-dot{display:inline-grid;place-items:center;height:1.5rem;width:1.5rem;border-radius:999px;background:rgb(var(--accent,217 119 6)/.12);color:#D97706;font-size:.75rem;margin-right:.5rem}</style>
<?php ob_start(); ?>
<script>
(function () {
  const BTS = <?= json_encode(array_column($bts, null, 'id')) ?>;
  const $f = $('#lease-form'), $tb = $('#charges tbody');
  let unit = null, owners = [], rowN = 0;
  const occ = () => $f.find('[name=occupancy_type]:checked').val();
  function people() {
    const list = []; const t = $('#ln-tenant'); if (t.val()) list.push({ id: t.val(), name: t.find(':selected').text().split(' · ')[0], role: 'Tenant' });
    $('#co-list select').each(function () { if (this.value) list.push({ id: this.value, name: $(this).find(':selected').text().split(' · ')[0], role: 'Co-tenant' }); });
    const o = $('#ln-owner'); if (o.val()) list.push({ id: o.val(), name: o.find(':selected').text().split(' · ')[0], role: 'Owner' });
    owners.forEach(x => { if (!list.find(y => String(y.id) === String(x.id))) list.push({ id: x.id, name: x.name, role: 'Co-owner' }); });
    return list;
  }
  function payerOptions(sel) { return people().map(p => `<option value="${p.id}" ${String(p.id) === String(sel) ? 'selected' : ''}>${VK.esc(p.name)} (${p.role})</option>`).join('') || '<option value="">Add people first</option>'; }
  function row(c) {
    const n = rowN++, bt = BTS[c.bill_type_id] || {}, metered = (c.billing_method || bt.billing_method) === 'metered';
    return `<tr data-row="${n}"><td><input type="checkbox" name="charges[${n}][on]" value="1" checked class="accent-amber-600 chg-on"></td>
      <td><b>${VK.esc(bt.name || c.bill_type)}</b><input type="hidden" name="charges[${n}][bill_type_id]" value="${c.bill_type_id}"><input type="hidden" name="charges[${n}][billing_method]" value="${metered ? 'metered' : (c.frequency === 'one_off' ? 'one_off' : 'fixed')}">
        <span class="block text-[11px] text-muted">default bill to: ${bt.default_bill_to || 'tenant'}</span></td>
      <td class="text-xs">${metered ? '<i class="fa-solid fa-gauge text-sky-500"></i> Metered' : 'Fixed'}</td>
      <td>${metered ? `<div class="relative"><input name="charges[${n}][unit_rate]" value="${c.unit_rate ?? bt.default_unit_rate ?? ''}" class="vk-input !py-1.5 text-right chg-amt" type="number" step="any"><span class="absolute right-8 top-2 text-[10px] text-muted">/${VK.esc(bt.unit_label || 'unit')}</span></div>`
        : `<input name="charges[${n}][amount]" value="${c.amount ?? bt.default_amount ?? ''}" class="vk-input !py-1.5 text-right chg-amt" type="number" step="any">`}</td>
      <td><select name="charges[${n}][frequency]" class="vk-select !py-1.5">${['monthly', 'quarterly', 'semi_annual', 'annual', 'one_off'].map(x => `<option value="${x}" ${x === (c.frequency || 'monthly') ? 'selected' : ''}>${x.replace('_', ' ')}</option>`).join('')}</select></td>
      <td><select name="charges[${n}][bill_to_party_id]" class="vk-select !py-1.5 chg-payer" data-sel="${c.bill_to_party_id || ''}" data-default="${bt.default_bill_to || 'tenant'}">${payerOptions(c.bill_to_party_id)}</select></td></tr>`;
  }
  function refreshPayers() {
    const ppl = people(), tenant = ppl.find(p => p.role === 'Tenant'), owner = ppl.find(p => p.role === 'Owner' || p.role === 'Co-owner');
    $tb.find('.chg-payer').each(function () {
      let sel = $(this).data('touched') ? $(this).val() : null;
      if (!sel || !ppl.find(p => String(p.id) === String(sel))) { const ownerPays = ['owner_occupied', 'owner_vacant'].includes(occ()) || !tenant || $(this).data('default') === 'owner'; sel = ((ownerPays ? owner : tenant) || owner || tenant || {}).id; }
      $(this).html(payerOptions(sel));
    });
    summary();
  }
  function loadDefaults() {
    if (!unit) return;
    VK.api('billing/default-charges', { unit_id: unit.id, occupancy_type: occ(), tenant_id: $('#ln-tenant').val(), owner_id: $('#ln-owner').val() }, { method: 'POST' }).then(r => {
      $tb.html(r.charges.map(row).join('') || '<tr class="empty"><td colspan="6" class="text-center text-muted py-6">No default bills — add from the list below.</td></tr>'); refreshPayers();
    });
  }
  function summary() {
    if (!unit) return;
    let tot = 0; const lines = [];
    $tb.find('tr[data-row]').each(function () { if (!$(this).find('.chg-on').prop('checked')) return; const name = $(this).find('b').first().text(), freq = $(this).find('select').first().val(), metered = $(this).find('[name$="[unit_rate]"]').length, v = VK.num($(this).find('.chg-amt').val()), payer = $(this).find('.chg-payer option:selected').text();
      if (!metered && freq === 'monthly') tot += v; lines.push(`<div class="flex justify-between gap-2"><span>${VK.esc(name)} <span class="text-[11px] text-muted block">${VK.esc(payer)}</span></span><b class="tabular-nums whitespace-nowrap">${metered ? 'metered' : VK.money(v)}</b></div>`); });
    $('#sum').html(`<p><b>${VK.esc(unit.unit_no)}</b> · ${VK.esc(unit.property)}</p><p class="text-muted text-xs mb-2">${$f.find('[name=occupancy_type]:checked').closest('label').find('b').text()}</p>` + lines.join(''));
    $('#sum-total').text(VK.money(tot));
  }
  $('#ln-unit').on('change', function () {
    if (!this.value) return;
    VK.api('billing/unit-context', { unit_id: this.value }).then(r => {
      unit = r.unit; owners = r.owners;
      $('#unit-card').removeClass('hidden').html(`<div class="flex justify-between"><b>${VK.esc(unit.unit_no)}</b><span>${VK.esc(unit.status.replace('_', ' '))}</span></div><div class="text-muted">${VK.esc(unit.property)} · ${unit.usage}</div><div class="mt-1">Rent <b>${VK.money(unit.rent)}</b> · Service charge <b>${VK.money(unit.service_charge)}</b></div>` + (r.active_lease ? `<p class="mt-2 text-rose-600 font-semibold"><i class="fa-solid fa-triangle-exclamation"></i> Already has active account ${VK.esc(r.active_lease.lease_no)} — end it first.</p>` : ''));
      $('#owners-card').html(owners.length ? '<span class="vk-label">Owners on record</span>' + owners.map(o => `<div>${VK.esc(o.name)} <span class="text-muted">· ${o.share}%</span></div>`).join('') : '<p class="text-amber-600 text-xs mt-6"><i class="fa-solid fa-circle-info"></i> No owner on record for this unit.</p>');
      if (owners.length && !$('#ln-owner').val()) { $('#ln-owner').append(new Option(owners[0].name, owners[0].id, true, true)).trigger('vk:sync'); }
      if (unit.due_day) $('#ln-due').val(unit.due_day);
      $('#ln-dep').val(unit.rent && unit.deposit_months ? (unit.rent * unit.deposit_months).toFixed(2) : '');
      if (unit.purpose === 'owner_occupied' || unit.status === 'owner_occupied') $f.find('[value=owner_occupied]').prop('checked', true).trigger('change');
      loadDefaults();
    });
  });
  $f.on('change', '[name=occupancy_type]', loadDefaults);
  $('#ln-tenant, #ln-owner').on('change', refreshPayers);
  $f.on('change', '#co-list select', refreshPayers);
  $f.on('change', '.chg-payer', function (e) { if (e.originalEvent) $(this).data('touched', 1); });
  $f.on('input change', '#charges input, #charges select', summary);
  $('#reload-charges').on('click', loadDefaults);
  $('#add-bt').on('change', function () { if (!this.value) return; $tb.find('tr.empty').remove(); const bt = BTS[this.value]; $tb.append(row({ bill_type_id: this.value, billing_method: bt.billing_method, frequency: bt.frequency === 'one_off' ? 'one_off' : 'monthly' })); this.value = ''; refreshPayers(); });
  $('#add-co').on('click', () => { const $s = $(`<div class="flex gap-2"><div class="flex-1"><select name="co_tenants[]" class="vk-select" data-party-search data-role=""><option value="">Search…</option></select></div><button type="button" class="vk-icon-btn" onclick="$(this).parent().remove()"><i class="fa-solid fa-xmark"></i></button></div>`); $('#co-list').append($s); VK.initPickers($s); });
  $('#new-tenant').on('click', () => { const $m = $('#m-party'); const $pf = $m.find('form'); $pf[0].reset(); $pf.attr('data-stay', '1'); if (!$pf.find('[name=stay]').length) $pf.append('<input type="hidden" name="stay" value="1">'); $pf.find('[name=is_tenant]').prop('checked', true); VK.openModal($m); });
  $('#m-party form').on('vk:saved', (e, r) => { const $pf = $(e.target); const name = ($pf.find('[name=party_type]:checked').val() === 'company' ? $pf.find('[name=company_name]').val() : ($pf.find('[name=first_name]').val() + ' ' + $pf.find('[name=last_name]').val())).trim();
    $('#ln-tenant').append(new Option(name, r.id, true, true)).trigger('vk:sync').trigger('change'); VK.closeModal($('#m-party')); });
  if ($('#ln-unit').val()) $('#ln-unit').trigger('change');
})();
</script>
<?php page_script(ob_get_clean());
