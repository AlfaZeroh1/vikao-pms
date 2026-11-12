<?php
$title = 'Billing runs';
$subtitle = 'Preview then raise every invoice for a month in one go. Runs also happen automatically on each account\'s invoice day (cron).';
$runId = (int)($_GET['run'] ?? 0);
$run = $runId ? one("SELECT r.*, pr.name AS property, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS by_name FROM bln_billing_runs r LEFT JOIN prpt_properties pr ON pr.id = r.property_id LEFT JOIN sys_users u ON u.id = r.created_by WHERE r.id = ?", [$runId]) : null;
$runs = all("SELECT r.*, pr.name AS property, (SELECT COUNT(*) FROM bln_invoices i WHERE i.run_id = r.id AND i.sent_at IS NULL AND i.status <> 'void') AS unsent FROM bln_billing_runs r LEFT JOIN prpt_properties pr ON pr.id = r.property_id ORDER BY r.created_at DESC LIMIT 30");
$metered = all("SELECT id, name, billing_method FROM cfg_bill_types WHERE is_active = 1 AND category NOT IN ('deposit','penalty','short_stay','sale') ORDER BY sort_order");
$pendingReadings = (int)val("SELECT COUNT(*) FROM prpt_meter_readings WHERE status = 'pending'");
?>
<?php if ($run): $inv = all("SELECT i.*, pt.display_name, u.unit_no FROM bln_invoices i JOIN prpt_parties pt ON pt.id = i.party_id LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE i.run_id = ? ORDER BY u.unit_no", [$runId]); ?>
  <div class="vk-panel p-5 mb-6 flex flex-wrap items-center gap-4">
    <span class="grid h-12 w-12 place-items-center rounded-2xl bg-emerald-500/10 text-emerald-600 text-xl"><i class="fa-solid fa-circle-check"></i></span>
    <div><p class="font-bold text-lg"><?= e($run['run_no']) ?> · <?= month_label($run['period_month']) ?></p><p class="text-sm text-muted"><?= (int)$run['invoices_count'] ?> invoices · <?= money($run['total_amount']) ?> · <?= e($run['property'] ?: 'All properties') ?> · <?= e($run['trigger_type']) ?> by <?= e($run['by_name'] ?: 'system') ?> <?= time_ago($run['created_at']) ?></p></div>
    <?php if (can('billing.invoices.send') && array_filter($inv, fn($x) => !$x['sent_at'])): ?><button class="ml-auto vk-btn-accent" data-action="<?= e(url('api.php', ['r' => 'billing/run-send', 'run_id' => $runId])) ?>" data-confirm="Send all unsent invoices from this run?" data-icon="question"><i class="fa-solid fa-paper-plane"></i>Send all</button><?php endif; ?>
  </div>
  <div class="vk-panel overflow-hidden mb-8"><table class="vk-table"><thead><tr><th>Invoice</th><th>Unit</th><th>Customer</th><th>Status</th><th>Sent</th><th class="num">Total</th></tr></thead><tbody>
    <?php foreach ($inv as $i): ?><tr data-href="<?= e(page('billing/invoice', ['id' => $i['id']])) ?>"><td><b><?= e($i['invoice_no']) ?></b></td><td><?= e($i['unit_no']) ?></td><td><?= e($i['display_name']) ?></td><td><?= pill($i['status']) ?></td><td class="text-xs"><?= $i['sent_at'] ? fdatetime($i['sent_at']) : '—' ?></td><td class="num"><?= money($i['total'], false) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
<?php if (can('billing.runs.execute')): ?>
<div class="grid grid-cols-1 xl:grid-cols-[380px_1fr] gap-6 items-start mb-8">
  <form id="run-form" class="vk-panel p-5 space-y-4">
    <h3 class="font-bold"><i class="fa-solid fa-wand-magic-sparkles text-accent mr-2"></i>New run</h3>
    <?= ui_field('Billing month', ui_input('period_month', date('Y-m'), 'month')) ?>
    <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', null, 'All properties'))) ?>
    <div><span class="vk-label">Bills (none ticked = all)</span><div class="grid grid-cols-2 gap-1.5 text-sm">
      <?php foreach ($metered as $b): ?><label class="flex items-center gap-2"><input type="checkbox" name="bill_types[]" value="<?= $b['id'] ?>" class="accent-amber-600"><?= e($b['name']) ?><?= $b['billing_method'] === 'metered' ? ' <i class="fa-solid fa-gauge text-sky-500 text-[10px]"></i>' : '' ?></label><?php endforeach; ?>
    </div></div>
    <?php if ($pendingReadings): ?><p class="text-xs rounded-xl bg-amber-500/10 text-amber-700 dark:text-amber-300 p-3"><i class="fa-solid fa-triangle-exclamation"></i> <?= $pendingReadings ?> meter reading(s) are awaiting approval and won't be billed. <a class="underline font-semibold" href="<?= e(page('property/readings', ['tab' => 'pending'])) ?>">Review</a></p><?php endif; ?>
    <label class="vk-switch"><input type="checkbox" name="send" value="1" checked>Send invoices immediately</label>
    <div class="flex gap-2"><button type="button" class="vk-btn-ghost flex-1 justify-center" id="btn-preview"><i class="fa-solid fa-eye"></i>Preview</button><button type="button" class="vk-btn-accent flex-1 justify-center" id="btn-run" disabled><i class="fa-solid fa-bolt"></i>Raise invoices</button></div>
  </form>
  <div class="vk-panel overflow-hidden" id="preview"><?= ui_empty('fa-eye', 'Preview first', 'See every invoice the run would raise — per account and payer — before anything is created.') ?></div>
</div>
<?php endif; ?>
<h3 class="font-bold mb-3">Recent runs</h3>
<div class="vk-panel overflow-hidden"><table class="vk-table"><thead><tr><th>Run</th><th>Period</th><th>Property</th><th>Trigger</th><th class="num">Invoices</th><th class="num">Total</th><th>Unsent</th><th>When</th></tr></thead><tbody>
  <?php foreach ($runs as $r): ?><tr data-href="<?= e(page('billing/runs', ['run' => $r['id']])) ?>"><td><b><?= e($r['run_no']) ?></b></td><td><?= month_label($r['period_month']) ?></td><td><?= e($r['property'] ?: 'All') ?></td><td><?= pill($r['trigger_type'] === 'scheduled' ? 'active' : 'pending', ucfirst($r['trigger_type'])) ?></td><td class="num"><?= (int)$r['invoices_count'] ?></td><td class="num"><?= money($r['total_amount'], false) ?></td><td><?= $r['unsent'] ? pill('overdue', $r['unsent'] . ' unsent') : '—' ?></td><td class="text-xs"><?= fdatetime($r['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table><?= $runs ? '' : ui_empty('fa-wand-magic-sparkles', 'No runs yet') ?></div>
<?php ob_start(); ?>
<script>
$('#btn-preview').on('click', function () {
  const $b = $(this); VK.btnLoading($b, true, 'Calculating');
  VK.api('billing/run-preview', $('#run-form').serialize()).then(r => { VK.btnLoading($b, false); $('#btn-run').prop('disabled', !r.count);
    $('#preview').html(r.count ? `<div class="flex items-center px-5 py-3.5 border-b border-line"><b>${r.count} invoices</b><span class="ml-auto font-extrabold text-lg">${VK.money(r.total)}</span></div><div class="max-h-[60vh] overflow-y-auto"><table class="vk-table"><thead><tr><th>Account</th><th>Bill to</th><th>Lines</th><th class="num">Total</th></tr></thead><tbody>` +
      r.rows.map(x => `<tr><td><b>${VK.esc(x.unit_no)}</b><span class="block text-xs text-muted">${VK.esc(x.lease_no)}</span></td><td>${VK.esc(x.party)}</td><td class="text-xs">${x.lines.map(l => VK.esc(l.description) + ' — <b>' + (l.quantity * l.unit_price).toFixed(2) + '</b>').join('<br>')}</td><td class="num font-bold">${x.total.toLocaleString('en-KE', { minimumFractionDigits: 2 })}</td></tr>`).join('') + '</tbody></table></div>'
      : '<div class="py-14 text-center text-muted"><i class="fa-solid fa-circle-check text-3xl text-emerald-500"></i><p class="mt-2 font-semibold">Nothing due for that period</p><p class="text-sm">Every account has already been invoiced, or no approved readings are waiting.</p></div>');
  }).catch(() => VK.btnLoading($b, false));
});
$('#btn-run').on('click', async function () { if (!await VK.confirm({ title: 'Raise these invoices now?' })) return; const $b = $(this); VK.btnLoading($b, true, 'Raising');
  VK.api('billing/run-execute', $('#run-form').serialize()).then(r => { VK.toast(r.message); if (r.redirect) location.href = r.redirect; }).catch(() => VK.btnLoading($b, false)); });
</script>
<?php page_script(ob_get_clean());
