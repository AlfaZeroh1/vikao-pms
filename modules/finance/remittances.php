<?php
$title = 'Owner remittances';
$subtitle = 'Collections on owners\' units less management fees, VAT and owner expenses — prepared, approved and paid';
$st = (string)($_GET['status'] ?? '');
$w = [scope_sql('r.property_id')]; $p = [];
if ($st) { $w[] = 'r.status = :s'; $p['s'] = $st; }
if ($pid = (int)($_GET['property_id'] ?? 0)) { $w[] = 'r.property_id = :p'; $p['p'] = $pid; }
$rows = all("SELECT r.*, pt.display_name, pr.name AS property FROM fin_remittances r JOIN prpt_parties pt ON pt.id = r.party_id JOIN prpt_properties pr ON pr.id = r.property_id WHERE " . implode(' AND ', $w) . " ORDER BY r.created_at DESC LIMIT 300", $p);
$k = one("SELECT COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-01-01') THEN net_payable END),0) paid_ytd, COALESCE(SUM(CASE WHEN status IN ('draft','approved') THEN net_payable END),0) due, COALESCE(SUM(CASE WHEN status = 'paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-01-01') THEN mgmt_fee END),0) fees FROM fin_remittances");
$actions = '<button class="vk-btn-accent" data-modal-open="#m-rem"><i class="fa-solid fa-calculator"></i>Prepare remittances</button>';
?>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5"><?= ui_stat('fa-hand-holding-dollar', 'Paid to owners (YTD)', money($k['paid_ytd'], true, true), 'green') ?><?= ui_stat('fa-hourglass-half', 'Prepared, not yet paid', money($k['due'], true, true), 'accent', null, page('finance/remittances', ['status' => 'approved'])) ?><?= ui_stat('fa-percent', 'Management fees earned (YTD)', money($k['fees'], true, true), 'violet') ?></div>
<div class="flex flex-wrap gap-2 mb-4"><?php foreach (['' => 'All', 'draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid', 'cancelled' => 'Cancelled'] as $kk => $l): ?><a class="vk-chip <?= $st === $kk ? '!border-accent !bg-accent/10' : '' ?>" href="<?= e(page('finance/remittances', ['status' => $kk])) ?>"><?= $l ?></a><?php endforeach; ?></div>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Remittance</th><th>Owner</th><th>Period</th><th class="num">Collected</th><th class="num">Mgmt fee + VAT</th><th class="num">Expenses</th><th class="num">Net payable</th><th>Status</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr data-href="<?= e(page('finance/remittance', ['id' => $r['id']])) ?>"><td><b><?= e($r['remittance_no']) ?></b><span class="block text-xs text-muted"><?= e($r['property']) ?></span></td><td><?= e($r['display_name']) ?></td><td class="text-xs"><?= fdate($r['period_start']) ?> – <?= fdate($r['period_end']) ?></td>
    <td class="num"><?= money($r['gross_collected'], false) ?></td><td class="num">−<?= money($r['mgmt_fee'] + $r['mgmt_fee_vat'], false) ?></td><td class="num">−<?= money($r['expenses'], false) ?></td><td class="num font-extrabold"><?= money($r['net_payable'], false) ?></td><td><?= pill($r['status']) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows) echo '<tr><td colspan="8">' . ui_empty('fa-hand-holding-dollar', 'No remittances yet', 'Prepare them at month end for every owner in a property.') . '</td></tr>'; ?>
  </tbody></table></div></div>
<?php
$body = '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Property *', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', null, 'Choose…'), 'required id="rm-prop"'))
  . ui_field('Owner <span class="font-normal text-muted">(blank = every owner)</span>', ui_party_picker('party_id', null, 'owner', 'id="rm-owner"'))
  . ui_field('From *', ui_input('from', date('Y-m-01', strtotime('first day of last month')), 'date', 'required id="rm-from"')) . ui_field('To *', ui_input('to', date('Y-m-t', strtotime('last day of last month')), 'date', 'required id="rm-to"')) . '</div>'
  . '<label class="vk-switch"><input type="checkbox" name="include_no_agreement" value="1">Include owners without a management agreement</label>'
  . '<div id="rm-preview" class="rounded-xl border border-line p-4 text-sm hidden"></div>';
echo ui_modal('m-rem', 'Prepare remittances', $body, url('api.php', ['r' => 'finance/create-remittance']), 'Prepare', 'max-w-2xl', 'fa-calculator');
ob_start(); ?>
<script>
function rmPreview() { const o = $('#rm-owner').val(), p = $('#rm-prop').val(); if (!o || !p) return $('#rm-preview').addClass('hidden');
  VK.api('finance/remittance-preview', { party_id: o, property_id: p, from: $('#rm-from').val(), to: $('#rm-to').val() }).then(r => { const x = r.pos;
    $('#rm-preview').removeClass('hidden').html(`<p class="font-bold mb-2">Preview</p>${x.lines.map(l => `<div class="flex justify-between"><span>${VK.esc(l.unit)} · ${VK.esc(l.bill)} <span class="text-muted">(${l.share_pct}%)</span></span><b>${VK.money(l.owner_amount)}</b></div>`).join('') || '<p class="text-muted">No owner income collected in this period.</p>'}
      <hr class="my-2 border-line"><div class="flex justify-between"><span>Gross</span><b>${VK.money(x.gross)}</b></div><div class="flex justify-between"><span>Fee (${VK.esc(x.fee_label)})</span><b>−${VK.money(x.fee)}</b></div><div class="flex justify-between"><span>VAT on fee</span><b>−${VK.money(x.fee_vat)}</b></div><div class="flex justify-between"><span>Owner expenses</span><b>−${VK.money(x.expenses)}</b></div>${x.withholding > 0 ? `<div class="flex justify-between"><span>Withholding</span><b>−${VK.money(x.withholding)}</b></div>` : ''}<div class="flex justify-between text-base mt-1"><span class="font-bold">Net payable</span><b class="text-emerald-600">${VK.money(x.net)}</b></div>`); }); }
$('#rm-owner, #rm-prop, #rm-from, #rm-to').on('change', rmPreview);
</script>
<?php page_script(ob_get_clean());
