<?php
require_once __DIR__ . '/_partials.php';
$title = 'Document templates';
$subtitle = 'Lease, sale and management agreements generated automatically with {{placeholders}}';
$id = (int)($_GET['id'] ?? 0);
$tpls = all("SELECT id, code, name, doc_type, usage_class, is_default, updated_at FROM prpt_doc_templates ORDER BY doc_type, name");
$t = $id ? one("SELECT * FROM prpt_doc_templates WHERE id = ?", [$id]) : (isset($_GET['new']) ? ['id' => '', 'code' => '', 'name' => '', 'doc_type' => 'lease', 'usage_class' => 'any', 'body_html' => '<h2 style="text-align:center">TENANCY AGREEMENT</h2><p>This agreement is made on {{date}} between <b>{{owner_names}}</b> (through the agent {{company_name}}) and <b>{{tenant_names}}</b>, ID {{tenant_ids}}.</p>', 'is_default' => 0] : ($tpls ? one("SELECT * FROM prpt_doc_templates WHERE id = ?", [$tpls[0]['id']]) : null));
$placeholders = ['lease_no' => 'Account / lease number', 'date' => "Today's date", 'company_name' => 'Agency name', 'company_address' => 'Agency address', 'tenant_names' => 'Tenant name(s)', 'tenant_ids' => 'Tenant ID number(s)', 'tenant_phone' => 'Tenant phone', 'tenant_email' => 'Tenant email',
    'owner_names' => 'Landlord / owner name(s)', 'property_name' => 'Property', 'property_address' => 'Property address', 'lr_number' => 'L.R. number', 'unit_no' => 'Unit number', 'block' => 'Block', 'unit_type' => 'Unit type', 'bedrooms' => 'Bedrooms', 'usage' => 'Residential / commercial',
    'start_date' => 'Start date', 'end_date' => 'End date', 'term' => 'Term', 'rent_amount' => 'Monthly rent', 'rent_words' => 'Rent in words', 'deposit' => 'Deposit', 'billing_day' => 'Invoice day', 'due_day' => 'Due day', 'notice_days' => 'Notice period (days)', 'escalation' => 'Rent escalation',
    'charges_table' => 'Table of all charges', 'paybill' => 'Paybill number', 'account_ref' => 'Payment account reference'];
$actions = '<a class="vk-btn-accent" href="' . e(page('property/templates', ['new' => 1])) . '"><i class="fa-solid fa-plus"></i>New template</a>';
?>
<div class="grid grid-cols-1 lg:grid-cols-[260px_1fr] gap-6">
  <div class="vk-panel p-2 h-max">
    <?php foreach ($tpls as $x): ?>
      <a href="<?= e(page('property/templates', ['id' => $x['id']])) ?>" class="block rounded-xl px-3 py-2.5 <?= $t && (int)$t['id'] === (int)$x['id'] ? 'bg-accent/10' : 'hover:bg-surface2' ?>">
        <b class="text-sm block"><?= e($x['name']) ?></b><span class="text-xs text-muted"><?= e(str_replace('_', ' ', $x['doc_type'])) ?> · <?= e($x['usage_class']) ?><?= $x['is_default'] ? ' · default' : '' ?></span></a>
    <?php endforeach; if (!$tpls) echo '<p class="p-3 text-sm text-muted">No templates yet.</p>'; ?>
  </div>
  <?php if ($t): ?>
  <form data-ajax="<?= e(url('api.php', ['r' => 'property/save-template'])) ?>" class="space-y-4" id="tpl-form" data-stay>
    <input type="hidden" name="id" value="<?= e($t['id']) ?>">
    <div class="vk-panel p-5 grid grid-cols-1 sm:grid-cols-4 gap-4">
      <?= ui_field('Name *', ui_input('name', $t['name'], 'text', 'required'), 'sm:col-span-2') ?>
      <?= ui_field('Code *', ui_input('code', $t['code'], 'text', 'required')) ?>
      <?= ui_field('Type', ui_select('doc_type', ui_kv_options(['lease' => 'Lease / tenancy', 'sale_agreement' => 'Sale agreement', 'mgmt_agreement' => 'Management agreement', 'notice' => 'Notice', 'receipt' => 'Receipt', 'other' => 'Other'], $t['doc_type']))) ?>
      <?= ui_field('For', ui_select('usage_class', ui_kv_options(['any' => 'Any unit', 'residential' => 'Residential', 'commercial' => 'Commercial'], $t['usage_class']))) ?>
      <div class="sm:col-span-2 pt-6"><label class="vk-switch"><input type="checkbox" name="is_default" value="1" <?= $t['is_default'] ? 'checked' : '' ?>>Default for this type — used automatically when an account is opened</label></div>
      <div class="flex items-end justify-end gap-2"><button type="button" class="vk-btn-ghost" id="tpl-preview"><i class="fa-solid fa-eye"></i>Preview</button><button class="vk-btn-accent"><i class="fa-solid fa-floppy-disk"></i>Save</button></div>
    </div>
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_280px] gap-4">
      <div class="vk-panel overflow-hidden"><div id="tpl-editor" class="min-h-[520px] bg-white text-slate-900"><?= $t['body_html'] ?></div><textarea name="body_html" class="hidden"><?= e($t['body_html']) ?></textarea></div>
      <div class="vk-panel p-4 h-max xl:sticky xl:top-20">
        <p class="font-bold text-sm mb-1">Placeholders</p><p class="text-xs text-muted mb-3">Click to insert at the cursor.</p>
        <div class="space-y-1 max-h-[480px] overflow-y-auto">
          <?php foreach ($placeholders as $k => $l): ?><button type="button" class="ph w-full text-left rounded-lg px-2 py-1.5 hover:bg-surface2 text-xs" data-ph="<?= e($k) ?>"><code class="text-accent font-bold">{{<?= e($k) ?>}}</code><span class="block text-muted"><?= e($l) ?></span></button><?php endforeach; ?>
        </div>
      </div>
    </div>
  </form>
  <?php else: echo '<div class="vk-panel">' . ui_empty('fa-file-signature', 'Create your first template') . '</div>'; endif; ?>
</div>
<div id="m-prev" class="vk-modal hidden"><div class="vk-modal-backdrop" data-modal-close></div><div class="vk-modal-card max-w-4xl"><div class="flex items-center px-6 py-4 border-b border-line"><h3 class="font-bold">Preview with sample data</h3><button class="ml-auto vk-icon-btn" data-modal-close><i class="fa-solid fa-xmark"></i></button></div><div class="p-8 max-h-[75vh] overflow-y-auto bg-white text-slate-900 doc-body" id="prev-body"></div></div></div>
<?php if ($t): ob_start(); ?>
<link href="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@1.3.7/dist/quill.min.js"></script>
<script>
(function () {
  let q = null;
  if (window.Quill) { q = new Quill('#tpl-editor', { theme: 'snow', modules: { toolbar: [[{ header: [1, 2, 3, false] }], ['bold', 'italic', 'underline'], [{ align: [] }], [{ list: 'ordered' }, { list: 'bullet' }], ['link', 'clean']] } }); }
  else $('#tpl-editor').attr('contenteditable', true).addClass('p-6');
  const html = () => q ? q.root.innerHTML : $('#tpl-editor').html();
  $('.ph').on('click', function () { const tok = '{{' + $(this).data('ph') + '}}'; if (q) { const r = q.getSelection(true); q.insertText(r ? r.index : q.getLength(), tok); } else document.execCommand('insertText', false, tok); });
  $('#tpl-form').on('submit', function () { $(this).find('[name=body_html]').val(html()); });
  $('#tpl-form').on('vk:saved', (e, r) => { if (!$('[name=id]').val() && r.id) location.href = VK.url('index.php', { r: 'property/templates', id: r.id }); });
  const sample = <?= json_encode(['lease_no' => 'LSE-000123', 'date' => date('j F Y'), 'company_name' => setting('company_name', 'Vikao Property Management'), 'company_address' => setting('company_address', 'Nairobi'), 'tenant_names' => 'Jane Wanjiku', 'tenant_ids' => '29384756', 'tenant_phone' => '0712 345 678', 'tenant_email' => 'jane@example.com', 'owner_names' => 'Peter Otieno', 'property_name' => 'Kilimani Heights', 'property_address' => 'Argwings Kodhek Rd, Kilimani, Nairobi', 'lr_number' => '209/1234', 'unit_no' => 'A-304', 'block' => 'Block A', 'unit_type' => 'Apartment', 'bedrooms' => 2, 'usage' => 'Residential', 'start_date' => date('j F Y'), 'end_date' => date('j F Y', strtotime('+1 year -1 day')), 'term' => '12 months', 'rent_amount' => 'KSh 45,000.00', 'rent_words' => 'forty-five thousand Kenya Shillings', 'deposit' => 'KSh 90,000.00', 'billing_day' => '1st', 'due_day' => '5th', 'notice_days' => 30, 'escalation' => '5% every 12 months', 'charges_table' => '<table class="doc-table"><tr><th>Charge</th><th>Amount</th></tr><tr><td>Rent</td><td>KSh 45,000.00</td></tr><tr><td>Service charge</td><td>KSh 6,000.00</td></tr><tr><td>Water</td><td>Metered @ KSh 150 / m³</td></tr></table>', 'paybill' => setting('paybill_number', '247247'), 'account_ref' => 'A-304']) ?>;
  $('#tpl-preview').on('click', () => { $('#prev-body').html(html().replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (m, k) => sample[k] !== undefined ? sample[k] : '<mark>' + m + '</mark>')); VK.openModal('#m-prev'); });
})();
</script>
<?php page_script(ob_get_clean()); endif;
