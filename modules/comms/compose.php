<?php
$title = 'Send message';
$subtitle = 'Email, SMS or portal announcements to tenants and owners — with personalised placeholders';
$partyId = (int)($_GET['party_id'] ?? 0);
$party = $partyId ? one("SELECT id, display_name, email, phone FROM prpt_parties WHERE id = ?", [$partyId]) : null;
$tpls = all("SELECT id, code, name, channel, category FROM com_templates ORDER BY category, name");
$preTpl = (string)($_GET['template'] ?? '');
$aud = (string)($_GET['audience'] ?? ($party ? 'single' : 'all'));
$ph = ['first_name' => 'First name', 'name' => 'Full name', 'unit_no' => 'Unit / house', 'property_name' => 'Property', 'lease_no' => 'Account no.', 'balance|money' => 'Account balance', 'arrears|money' => 'Overdue amount', 'end_date' => 'Lease end date', 'paybill' => 'Paybill', 'account_ref' => 'Pay-to account', 'portal_url' => 'Portal link', 'company_name' => 'Company', 'support_phone' => 'Support phone', 'today' => "Today's date"];
?>
<form data-ajax="<?= e(url('api.php', ['r' => 'comms/save-campaign'])) ?>" id="cmp" class="grid grid-cols-1 xl:grid-cols-[1fr_380px] gap-6 items-start">
  <div class="space-y-5">
    <section class="vk-panel p-5">
      <h3 class="font-bold mb-3">1 · Who</h3>
      <?php if ($party): ?><input type="hidden" name="party_id" value="<?= $party['id'] ?>"><div class="flex items-center gap-3 rounded-xl bg-surface2/60 p-3"><?= ui_avatar($party['display_name']) ?><div><b><?= e($party['display_name']) ?></b><span class="block text-xs text-muted"><?= e(trim($party['email'] . ' · ' . $party['phone'], ' ·')) ?></span></div></div>
      <?php else: ?>
      <div class="flex flex-wrap gap-2">
        <?php foreach (['all' => ['Everyone', 'fa-users'], 'tenants' => ['Active tenants', 'fa-house-user'], 'owners' => ['Home owners', 'fa-key'], 'arrears' => ['People in arrears', 'fa-triangle-exclamation'], 'lease_expiring' => ['Leases expiring', 'fa-hourglass-half'], 'custom' => ['Pick people', 'fa-list-check']] as $k => [$l, $ic]): ?>
          <label class="vk-choice"><input type="radio" name="audience" value="<?= $k ?>" <?= $aud === $k ? 'checked' : '' ?> data-show-when><span><i class="fa-solid <?= $ic ?>"></i><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
        <div class="sm:col-span-2"><span class="vk-label">Properties (none = all)</span><div class="flex flex-wrap gap-2"><?php foreach (properties_for_select() as $pr): ?><label class="vk-choice !py-1"><input type="checkbox" name="property_ids[]" value="<?= $pr['id'] ?>" <?= (int)($_GET['property_id'] ?? 0) === (int)$pr['id'] ? 'checked' : '' ?>><span class="text-xs"><?= e($pr['name']) ?></span></label><?php endforeach; ?></div></div>
        <div data-when-audience="arrears"><?= ui_field('Overdue at least (KSh)', ui_input('min_arrears', 1, 'number')) ?></div>
        <div data-when-audience="lease_expiring"><?= ui_field('Ending within (days)', ui_input('days', 60, 'number')) ?></div>
        <div class="sm:col-span-3" data-when-audience="custom"><span class="vk-label">People</span><div id="picked" class="flex flex-wrap gap-2 mb-2"></div><select data-party-search id="pick-one" class="vk-select"><option value="">Search and add…</option></select></div>
      </div>
      <?php endif; ?>
    </section>
    <section class="vk-panel p-5 space-y-4">
      <h3 class="font-bold">2 · What</h3>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <?= ui_field('Start from a template', ui_select('template_id', ui_options($tpls, 'id', 'name', null, 'Blank message'), 'id="tpl"')) ?>
        <?= ui_field('Channel', ui_select('channel', ui_kv_options(['both' => 'Email + SMS', 'email' => 'Email', 'sms' => 'SMS', 'portal' => 'Portal announcement only'], 'both'), 'id="chan"')) ?>
        <?= ui_field('Campaign name (internal)', ui_input('name', '', 'text', 'placeholder="e.g. Water shutdown notice"')) ?>
      </div>
      <div id="subj-wrap"><?= ui_field('Subject', ui_input('subject', '', 'text', 'id="subj"')) ?></div>
      <div><div class="flex items-center"><span class="vk-label">Message</span><span class="ml-auto text-xs text-muted" id="sms-meter"></span></div><textarea name="body" id="body" rows="9" class="vk-textarea" placeholder="Hi {{first_name}}, …" required></textarea>
        <div class="mt-2 flex flex-wrap gap-1"><?php foreach ($ph as $k => $l): ?><button type="button" class="ph rounded-lg border border-line px-2 py-1 text-[11px] hover:border-accent" data-ph="<?= e($k) ?>" title="<?= e($l) ?>">{{<?= e($k) ?>}}</button><?php endforeach; ?></div></div>
    </section>
    <section class="vk-panel p-5 flex flex-wrap items-end gap-4">
      <?= ui_field('Send later (optional)', ui_input('scheduled_at', '', 'datetime-local'), 'w-60') ?>
      <p class="text-xs text-muted flex-1">Scheduled campaigns go out from the cron job. Every message is logged per person and appears in their portal inbox.</p>
      <button class="vk-btn-accent"><i class="fa-solid fa-paper-plane"></i>Send</button>
    </section>
  </div>
  <aside class="vk-panel p-5 xl:sticky xl:top-20 space-y-3">
    <h3 class="font-bold">Preview</h3>
    <div id="aud-sum" class="text-sm text-muted">Calculating…</div>
    <div class="rounded-xl border border-line overflow-hidden"><div class="px-3 py-2 bg-surface2/60 text-xs"><b id="pv-subj">—</b><span class="block text-muted" id="pv-to"></span></div><div class="p-3 text-sm max-h-80 overflow-y-auto" id="pv-body"><span class="text-muted">Write a message to see it rendered for the first recipient.</span></div></div>
  </aside>
</form>
<?php ob_start(); ?>
<script>
(function () {
  const $f = $('#cmp'); let t;
  function preview() { clearTimeout(t); t = setTimeout(() => VK.api('comms/audience-preview', $f.serialize(), { silent: true }).then(r => {
    $('#aud-sum').html(`<b class="text-ink text-lg">${r.count}</b> recipient(s) · ${r.with_email} with email · ${r.with_phone} with phone` + (r.names.length ? `<p class="text-xs mt-1">${r.names.map(VK.esc).join(', ')}${r.count > r.names.length ? '…' : ''}</p>` : ''));
    $('#pv-subj').text(r.subject || '(no subject)'); $('#pv-to').text(r.sample_name ? 'To ' + r.sample_name : ''); $('#pv-body').html(r.html || '<span class="text-muted">—</span>');
    const ch = $('#chan').val(); $('#sms-meter').text(['sms', 'both'].includes(ch) ? r.sms_len + ' chars · ' + Math.max(1, Math.ceil(r.sms_len / 160)) + ' SMS' : '');
  }), 350); }
  $f.on('input change', 'input, select, textarea', preview);
  $('#chan').on('change', function () { $('#subj-wrap').toggle(this.value !== 'sms'); }).trigger('change');
  $('#tpl').on('change', function () { if (!this.value) return; VK.api('comms/template', { id: this.value }).then(r => { const x = r.t; $('#subj').val(x.subject || ''); $('#body').val($('#chan').val() === 'sms' ? (x.body_sms || $('<div>').html(x.body_email).text()) : (x.body_email || x.body_sms || '')); $('#chan').val(x.channel === 'portal' ? 'portal' : $('#chan').val()).trigger('change'); preview(); }); });
  $('.ph').on('click', function () { const el = document.getElementById('body'), tok = '{{' + $(this).data('ph') + '}}', s = el.selectionStart || el.value.length; el.value = el.value.slice(0, s) + tok + el.value.slice(el.selectionEnd || s); el.focus(); el.selectionStart = el.selectionEnd = s + tok.length; preview(); });
  $('#pick-one').on('change', function () { if (!this.value || $(`#picked [value="${this.value}"]`).length) return; const name = $(this).find(':selected').text().split(' · ')[0];
    $('#picked').append(`<span class="vk-chip"><input type="hidden" name="party_ids[]" value="${this.value}">${VK.esc(name)}<button type="button" onclick="$(this).parent().remove();$('#cmp').trigger('change')"><i class="fa-solid fa-xmark"></i></button></span>`); $(this).val('').trigger('vk:sync'); preview(); });
  <?php if ($preTpl): ?>const pre = $('#tpl option').filter((i, o) => o.text && <?= json_encode(array_column($tpls, 'id', 'code')) ?>[<?= json_encode($preTpl) ?>] == o.value); if (pre.length) $('#tpl').val(pre.val()).trigger('change');<?php endif; ?>
  preview();
})();
</script>
<?php page_script(ob_get_clean());
