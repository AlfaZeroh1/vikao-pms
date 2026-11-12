<?php
$title = 'Statements';
$subtitle = 'Tenant, owner, house, account, property and landlord statements — all bills or one bill type, any period';
$types = ['party' => ['Tenant / payer', 'fa-user', 'Everything a person was billed and paid'], 'landlord' => ['Landlord', 'fa-user-tie', 'Owner earnings, fees, expenses, remitted vs unremitted'], 'unit' => ['House / unit', 'fa-door-open', 'Every bill on a house, any payer'],
          'lease' => ['Account / lease', 'fa-file-contract', 'One billing account'], 'property' => ['Property', 'fa-building', 'Per-unit invoiced, collected, arrears + expenses']];
$t = $_GET['type'] ?? 'party';
?>
<form method="get" class="vk-panel p-6 max-w-3xl space-y-5">
  <input type="hidden" name="r" value="finance/statement">
  <div><span class="vk-label">Statement type</span><div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
    <?php foreach ($types as $k => [$l, $ic, $d]): ?><label class="cursor-pointer"><input type="radio" name="type" value="<?= $k ?>" class="peer sr-only" <?= $t === $k ? 'checked' : '' ?> data-show-when>
      <span class="block h-full rounded-xl border-2 border-line p-3 peer-checked:border-accent peer-checked:bg-accent/5"><i class="fa-solid <?= $ic ?> text-accent"></i> <b class="text-sm"><?= $l ?></b><span class="block text-[11px] text-muted"><?= $d ?></span></span></label><?php endforeach; ?>
  </div></div>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
    <div data-when-type="party"><?= ui_field('Person', ui_party_picker('id_party', null)) ?></div>
    <div data-when-type="landlord"><?= ui_field('Owner', ui_party_picker('id_landlord', null, 'owner')) ?></div>
    <div data-when-type="unit"><?= ui_field('House / unit', ui_unit_picker('id_unit')) ?></div>
    <div data-when-type="lease"><?= ui_field('Account', ui_select('id_lease', ui_options(all("SELECT l.id, CONCAT(l.lease_no, ' · ', u.unit_no) AS name FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id WHERE " . scope_sql('l.property_id') . " ORDER BY l.status IN ('active','notice') DESC, u.unit_no LIMIT 1000"), 'id', 'name', null, 'Choose…'))) ?></div>
    <div data-when-type="property"><?= ui_field('Property', ui_select('id_property', ui_options(properties_for_select(), 'id', 'name', null, 'Choose…'))) ?></div>
    <div data-when-type="party,unit,lease,property"><?= ui_field('Bill type', ui_select('bill_type_id', ui_options(bill_types_for_select(), 'id', 'name', null, 'All bills'))) ?></div>
    <?= ui_field('From', ui_input('from', date('Y-01-01'), 'date')) ?><?= ui_field('To', ui_input('to', date('Y-m-d'), 'date')) ?>
  </div>
  <button class="vk-btn-accent"><i class="fa-solid fa-file-lines"></i>Generate statement</button>
</form>
<?php page_script('<script>$("form[method=get]").on("submit",function(){const t=$(this).find("[name=type]:checked").val();const v=$(this).find("[name=id_"+t+"]").val();if(!v){VK.toast("Choose who / what the statement is for","warning");return false}$(this).append(`<input type=hidden name=id value="${v}">`);$(this).find("[name^=id_]").prop("disabled",true)})</script>');
