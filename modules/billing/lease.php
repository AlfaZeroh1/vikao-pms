<?php
require_once __DIR__ . '/../property/_partials.php';
$id = (int)($_GET['id'] ?? 0);
$l = one("SELECT l.*, u.unit_no, u.uuid AS unit_uuid, u.usage_class, pr.name AS property, t.name AS template FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id LEFT JOIN prpt_doc_templates t ON t.id = l.template_id WHERE l.id = ?", [$id]);
if (!$l) { echo ui_empty('fa-file-contract', 'Account not found'); return; }
$occL = ['tenant_managed' => 'Leased · we manage the tenant', 'tenant_owner_managed' => 'Leased · owner manages tenant', 'owner_occupied' => 'Owner occupied', 'owner_vacant' => 'Owned · not occupied', 'anonymous' => 'Anonymous occupant', 'company_let' => 'Company let', 'short_stay' => 'Short stay'];
$title = 'Account ' . $l['lease_no'];
$subtitle = e($l['unit_no'] . ' · ' . $l['property'] . ' · ' . ($occL[$l['occupancy_type']] ?? '')) . ' · ' . pill($l['status']);
$crumbs = [['Accounts & leases', page('billing/leases')], [$l['unit_no'], page('property/unit', ['id' => $l['unit_id']])]];
$tab = $_GET['tab'] ?? 'overview';
$parties = all("SELECT lp.*, p.display_name, p.phone, p.email, p.party_no, a.status AS portal FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id LEFT JOIN prt_accounts a ON a.party_id = p.id WHERE lp.lease_id = ? ORDER BY lp.is_primary DESC, FIELD(lp.role,'tenant','co_tenant','occupant','payer','owner','guarantor')", [$id]);
$charges = all("SELECT c.*, bt.name AS bt, bt.code, bt.unit_label, bt.color_hex, p.display_name AS payer FROM bln_lease_charges c JOIN cfg_bill_types bt ON bt.id = c.bill_type_id JOIN prpt_parties p ON p.id = c.bill_to_party_id WHERE c.lease_id = ? ORDER BY c.is_active DESC, bt.sort_order", [$id]);
$bal = one("SELECT COALESCE(SUM(total),0) inv, COALESCE(SUM(amount_paid),0) paid, COALESCE(SUM(balance),0) bal, COALESCE(SUM(CASE WHEN due_date < CURDATE() THEN balance END),0) overdue FROM bln_invoices WHERE lease_id = ? AND status NOT IN ('void','draft')", [$id]);
$credit = (float)val("SELECT COALESCE(SUM(remaining),0) FROM fin_credits WHERE lease_id = ? AND status = 'open'", [$id]);
$dep = one("SELECT * FROM bln_deposits WHERE lease_id = ? ORDER BY id LIMIT 1", [$id]);
$live = in_array($l['status'], ['active', 'notice'], true); $canM = can('billing.leases.manage');
$a = [];
if ($l['agreement_document_id']) $a[] = '<a class="vk-btn-ghost" target="_blank" href="' . e(page('property/document', ['id' => $l['agreement_document_id']])) . '"><i class="fa-solid fa-file-signature"></i>Agreement</a>';
$a[] = '<a class="vk-btn-ghost" href="' . e(page('finance/statement', ['type' => 'lease', 'id' => $id])) . '"><i class="fa-solid fa-file-lines"></i>Statement</a>';
if (can('billing.invoices.create')) $a[] = '<a class="vk-btn-ghost" href="' . e(page('billing/invoice-edit', ['lease_id' => $id])) . '"><i class="fa-solid fa-file-circle-plus"></i>Invoice</a>';
if (can('finance.payments.create')) $a[] = '<a class="vk-btn-accent" href="' . e(page('finance/payments', ['new' => 1, 'lease_id' => $id])) . '"><i class="fa-solid fa-money-bill"></i>Receive payment</a>';
$actions = implode('', $a);
echo ui_tabs(['overview' => ['Overview', 'fa-file-contract'], 'bills' => ['Bills followed', 'fa-list-check', count(array_filter($charges, fn($c) => $c['is_active']))], 'invoices' => ['Invoices & payments', 'fa-file-invoice'], 'timeline' => ['Journey', 'fa-route']], $tab, 'tab', ['id' => $id]);

if ($tab === 'overview'): ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
  <?= ui_stat('fa-scale-balanced', 'Balance', money($bal['bal']), $bal['bal'] > 0 ? 'red' : 'green', $bal['overdue'] > 0 ? money($bal['overdue']) . ' overdue' : null) ?>
  <?= ui_stat('fa-file-invoice', 'Invoiced to date', money($bal['inv'], true, true), 'blue') ?>
  <?= ui_stat('fa-money-bill-wave', 'Paid to date', money($bal['paid'], true, true), 'green') ?>
  <?= ui_stat('fa-piggy-bank', 'Credit / prepaid', money($credit), 'violet') ?>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="lg:col-span-2 space-y-6">
    <div class="vk-panel overflow-hidden">
      <div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold">People</h3>
        <?php if ($canM && $live): ?><button class="ml-auto vk-btn-ghost !py-1.5 text-xs" data-modal-open="#m-lp"><i class="fa-solid fa-plus"></i>Add person</button><?php endif; ?>
        <?php if (can('property.portal.invite')): ?><button class="<?= $canM && $live ? 'ml-2' : 'ml-auto' ?> vk-btn-ghost !py-1.5 text-xs" data-action="<?= e(url('api.php', ['r' => 'billing/send-portal-invites', 'id' => $id])) ?>" data-no-reload><i class="fa-solid fa-paper-plane"></i>Portal invites</button><?php endif; ?></div>
      <table class="vk-table"><tbody>
      <?php foreach ($parties as $p): ?>
        <tr data-href="<?= e(page('property/party', ['id' => $p['party_id']])) ?>"><td><div class="flex items-center gap-3"><?= ui_avatar($p['display_name']) ?><div><b><?= e($p['display_name']) ?></b><?= $p['is_primary'] ? ' ' . pill('active', 'Primary') : '' ?><span class="block text-xs text-muted"><?= e(trim($p['phone'] . ' · ' . $p['email'], ' ·')) ?></span></div></div></td>
          <td><?= e(ucwords(str_replace('_', ' ', $p['role']))) ?></td><td><?= $p['portal'] === 'active' ? pill('active', 'Portal active') : pill('pending', 'Portal ' . ($p['portal'] ?: 'none')) ?></td>
          <td class="text-right"><?php if ($canM && !$p['is_primary']): ?><button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'billing/remove-lease-party', 'id' => $p['id']])) ?>" data-confirm="Remove from this account?"><i class="fa-solid fa-xmark text-xs"></i></button><?php endif; ?></td></tr>
      <?php endforeach; ?>
      </tbody></table>
    </div>
    <?php if ($dep): ?>
    <div class="vk-panel p-5 flex flex-wrap items-center gap-4"><span class="grid h-11 w-11 place-items-center rounded-xl bg-violet-500/10 text-violet-600"><i class="fa-solid fa-vault"></i></span>
      <div><p class="font-bold">Deposit <?= pill($dep['status']) ?></p><p class="text-sm text-muted">Required <?= money($dep['amount_required']) ?> · held <?= money($dep['amount_held']) ?> by <?= e($dep['held_by']) ?><?= $dep['refunded_amount'] > 0 ? ' · refunded ' . money($dep['refunded_amount']) : '' ?></p></div>
      <a class="ml-auto vk-btn-ghost !py-2 text-sm" href="<?= e(page('billing/deposits', ['lease_id' => $id])) ?>">Manage</a></div>
    <?php endif; ?>
  </div>
  <div class="space-y-6">
    <div class="vk-panel p-5">
      <div class="flex items-center mb-3"><h3 class="font-bold">Terms</h3><?php if ($canM): ?><button class="ml-auto vk-btn-ghost !py-1 !px-2 text-xs" data-modal-open="#m-terms"><i class="fa-solid fa-pen"></i></button><?php endif; ?></div>
      <dl class="space-y-2 text-sm">
        <?php foreach ([['Start', fdate($l['start_date'])], ['End', $l['end_date'] ? fdate($l['end_date']) : 'Periodic'], ['Invoice day', ordinal((int)$l['billing_day'])], ['Due day', ordinal((int)$l['due_day'])], ['Auto-invoicing', $l['auto_invoice'] ? 'On' : 'Off'], ['Last invoiced', $l['last_invoiced_period'] ? month_label($l['last_invoiced_period']) : '—'],
                        ['Escalation', $l['escalation_pct'] ? num($l['escalation_pct'], 1) . '% / ' . $l['escalation_every_months'] . ' mo · next ' . fdate($l['next_escalation_date']) : '—'], ['Notice', $l['notice_period_days'] . ' days'], ['Template', $l['template'] ?: 'Default']] as [$k, $v]): ?>
          <div class="flex justify-between border-b border-line/60 pb-1.5"><dt class="text-muted"><?= $k ?></dt><dd class="font-semibold"><?= e($v) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <?php if ($l['notes']): ?><p class="mt-3 text-sm"><?= nl2br(e($l['notes'])) ?></p><?php endif; ?>
    </div>
    <?php if ($canM && $live): ?>
    <div class="vk-panel p-5 space-y-2">
      <h3 class="font-bold mb-1">Lifecycle</h3>
      <?php if (in_array($l['occupancy_type'], ['tenant_managed', 'tenant_owner_managed', 'company_let'], true)): ?><button class="vk-btn-ghost w-full justify-center" data-action="<?= e(url('api.php', ['r' => 'property/regen-agreement'])) ?>" data-payload='{"lease_id":<?= $id ?>}' data-confirm="Regenerate the agreement from the current template?" data-icon="question"><i class="fa-solid fa-rotate"></i>Regenerate agreement</button><?php endif; ?>
      <?php if ($l['status'] === 'active'): ?><button class="vk-btn-ghost w-full justify-center" data-modal-open="#m-notice"><i class="fa-solid fa-door-open"></i>Record notice to vacate</button><?php endif; ?>
      <button class="vk-btn-ghost w-full justify-center !text-rose-600" data-modal-open="#m-end"><i class="fa-solid fa-flag-checkered"></i>Close account / move out</button>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php
echo ui_modal('m-lp', 'Add person to account', '<input type="hidden" name="lease_id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Person *', ui_party_picker('party_id')) . ui_field('Role', ui_select('role', ui_kv_options(['co_tenant' => 'Co-tenant', 'occupant' => 'Occupant', 'guarantor' => 'Guarantor', 'payer' => 'Payer (e.g. employer)', 'owner' => 'Owner', 'tenant' => 'Tenant']))) . '</div>', url('api.php', ['r' => 'billing/add-lease-party']), 'Add', 'max-w-xl', 'fa-user-plus');
echo ui_modal('m-terms', 'Edit terms', '<input type="hidden" name="id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-3 gap-4">' . ui_field('End date', ui_input('end_date', $l['end_date'], 'date')) . ui_field('Invoice day', ui_input('billing_day', $l['billing_day'], 'number')) . ui_field('Due day', ui_input('due_day', $l['due_day'], 'number'))
    . ui_field('Escalation %', ui_input('escalation_pct', $l['escalation_pct'], 'number', 'step="0.01"')) . ui_field('Every (months)', ui_input('escalation_every_months', $l['escalation_every_months'], 'number')) . ui_field('Notice (days)', ui_input('notice_period_days', $l['notice_period_days'], 'number'))
    . ui_field('Template', ui_select('template_id', ui_options(all("SELECT id, name FROM prpt_doc_templates WHERE doc_type = 'lease'"), 'id', 'name', $l['template_id'], 'Default')), 'sm:col-span-2') . '<div class="pt-6"><label class="vk-switch"><input type="checkbox" name="auto_invoice" value="1"' . ($l['auto_invoice'] ? ' checked' : '') . '>Auto-invoice</label></div>'
    . '<div class="sm:col-span-3">' . ui_field('Notes', '<textarea name="notes" class="vk-textarea" rows="2">' . e($l['notes']) . '</textarea>') . '</div></div>', url('api.php', ['r' => 'billing/update-lease']), 'Save', 'max-w-2xl', 'fa-sliders');
echo ui_modal('m-notice', 'Notice to vacate', '<input type="hidden" name="id" value="' . $id . '">' . ui_field('Move-out date *', ui_input('end_date', date('Y-m-d', strtotime('+' . (int)$l['notice_period_days'] . ' days')), 'date')) . ui_field('Reason', ui_input('reason')), url('api.php', ['r' => 'billing/lease-notice']), 'Record notice', 'max-w-md', 'fa-door-open');
echo ui_modal('m-end', 'Close account', '<input type="hidden" name="id" value="' . $id . '"><p class="text-sm text-muted">Stops all bills from the date below and marks the unit vacant. Open invoices remain payable; settle the deposit on the Deposits page.</p>' . ui_field('End / move-out date', ui_input('end_date', $l['end_date'] && $l['end_date'] <= date('Y-m-d') ? $l['end_date'] : date('Y-m-d'), 'date')) . ui_field('Reason', ui_input('reason', 'Moved out'))
    . '<label class="vk-switch"><input type="checkbox" name="terminate" value="1">Terminated early (breach / eviction)</label>', url('api.php', ['r' => 'billing/end-lease']), 'Close account', 'max-w-md', 'fa-flag-checkered');

elseif ($tab === 'bills'): $partyOpts = array_map(fn($p) => ['id' => $p['party_id'], 'name' => $p['display_name'] . ' (' . str_replace('_', ' ', $p['role']) . ')'], $parties); ?>
<div class="vk-panel overflow-hidden">
  <div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold">Bills followed on this account</h3><?php if ($canM && $live): ?><button class="ml-auto vk-btn-accent !py-2 text-sm" data-crud-new="#m-charge" data-title="Add bill"><i class="fa-solid fa-plus"></i>Add bill</button><?php endif; ?></div>
  <table class="vk-table"><thead><tr><th>Bill</th><th>Amount</th><th>Frequency</th><th>Bill to</th><th>Since</th><th>Last billed</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($charges as $c): ?>
    <tr class="<?= $c['is_active'] ? '' : 'opacity-50' ?>"><td><span class="inline-block h-2.5 w-2.5 rounded-full mr-2" style="background:<?= e($c['color_hex']) ?>"></span><b><?= e($c['bt']) ?></b></td>
      <td><?= $c['billing_method'] === 'metered' ? '<i class="fa-solid fa-gauge text-sky-500 mr-1"></i>' . money($c['unit_rate'] ?? 0) . ' / ' . e($c['unit_label'] ?: 'unit') : money($c['amount']) ?><?= (float)$c['split_pct'] < 100 ? ' <span class="text-xs text-muted">(' . num($c['split_pct'], 1) . '%)</span>' : '' ?></td>
      <td><?= e(str_replace('_', ' ', $c['frequency'])) ?></td><td><?= e($c['payer']) ?></td><td class="text-xs"><?= fdate($c['start_date']) ?></td><td class="text-xs"><?= $c['last_billed_period'] ? month_label($c['last_billed_period']) : '—' ?></td>
      <td><?= $c['is_active'] ? pill('active') : pill('ended', 'Stopped') ?></td>
      <td class="text-right whitespace-nowrap"><?php if ($canM): ?><button class="vk-icon-btn !h-8 !w-8" data-crud-edit="#m-charge" data-row='<?= e(json_encode($c)) ?>' data-title="Edit <?= e($c['bt']) ?>"><i class="fa-solid fa-pen text-xs"></i></button>
        <button class="vk-icon-btn !h-8 !w-8" data-action="<?= e(url('api.php', ['r' => 'billing/delete-charge', 'id' => $c['id']])) ?>" data-confirm="Stop / remove this bill?"><i class="fa-regular fa-trash-can text-xs"></i></button><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table><?= $charges ? '' : ui_empty('fa-list-check', 'No bills on this account') ?>
</div>
<?php echo ui_modal('m-charge', 'Bill', '<input type="hidden" name="id"><input type="hidden" name="lease_id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
    . ui_field('Bill type *', ui_select('bill_type_id', ui_options(bill_types_for_select(), 'id', 'name'), 'required')) . ui_field('Bill to *', ui_select('bill_to_party_id', ui_options($partyOpts, 'id', 'name'), 'required'))
    . ui_field('Fixed amount', ui_input('amount', '', 'number', 'step="any"')) . ui_field('Rate per unit (metered)', ui_input('unit_rate', '', 'number', 'step="any"'))
    . ui_field('Frequency', ui_select('frequency', ui_kv_options(['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-annual', 'annual' => 'Annual', 'one_off' => 'One-off']))) . ui_field('Share of bill %', ui_input('split_pct', 100, 'number', 'step="0.001"'))
    . ui_field('From', ui_input('start_date', date('Y-m-d'), 'date')) . ui_field('Until', ui_input('end_date', '', 'date'))
    . '<label class="vk-switch"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked>Active</label></div><p class="text-xs text-muted">Split a bill between two payers (e.g. 50% tenant / 50% owner) by adding it twice with different shares.</p>', url('api.php', ['r' => 'billing/save-charge']), 'Save bill', 'max-w-2xl', 'fa-receipt');

elseif ($tab === 'invoices'):
    $inv = all("SELECT i.*, p.display_name FROM bln_invoices i JOIN prpt_parties p ON p.id = i.party_id WHERE i.lease_id = ? ORDER BY i.issue_date DESC, i.id DESC", [$id]);
    $pays = all("SELECT * FROM fin_payments WHERE lease_id = ? ORDER BY paid_at DESC", [$id]); ?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_400px] gap-6">
  <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Invoices</div><div class="overflow-x-auto"><table class="vk-table"><thead><tr><th>Invoice</th><th>Period</th><th>Billed to</th><th>Status</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>
    <?php foreach ($inv as $i): ?><tr data-href="<?= e(page('billing/invoice', ['id' => $i['id']])) ?>"><td><b><?= e($i['invoice_no']) ?></b><span class="block text-xs text-muted"><?= fdate($i['issue_date']) ?> · <?= e($i['source']) ?></span></td><td class="text-xs"><?= $i['period_month'] ? month_label($i['period_month']) : '—' ?></td><td class="text-sm"><?= e($i['display_name']) ?></td><td><?= pill($i['status']) ?></td>
      <td class="num"><?= money($i['total'], false) ?></td><td class="num"><?= (float)$i['amount_paid'] > 0 ? '<a href="#" class="text-emerald-600 font-semibold underline decoration-dotted" data-paid=\'{"invoice_id":' . $i['id'] . '}\' data-paid-title="Payments on ' . e($i['invoice_no']) . '">' . money($i['amount_paid'], false) . '</a>' : '0.00' ?></td><td class="num"><?= ui_money_cell($i['balance'], true) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?= $inv ? '' : ui_empty('fa-file-invoice', 'No invoices yet') ?></div>
  <div class="vk-panel overflow-hidden h-max"><div class="px-5 py-3.5 border-b border-line font-bold">Payments</div><table class="vk-table"><tbody>
    <?php foreach ($pays as $pm): ?><tr data-href="<?= e(page('finance/consume', ['payment' => $pm['uuid']])) ?>"><td><b><?= e($pm['receipt_no']) ?></b><span class="block text-xs text-muted"><?= fdatetime($pm['paid_at']) ?> · <?= e($pm['method']) ?> <?= e($pm['reference']) ?></span></td><td><?= pill($pm['status']) ?></td><td class="num font-bold"><?= money($pm['amount'], false) ?></td></tr><?php endforeach; ?>
  </tbody></table><?= $pays ? '' : ui_empty('fa-money-bill', 'No payments') ?></div>
</div>
<?php else:
    $items = [];
    foreach (all("SELECT * FROM sys_audit_log WHERE entity_type IN ('lease','lease_charge') AND (entity_id = ? OR (entity_type = 'lease_charge' AND entity_id IN (SELECT id FROM bln_lease_charges WHERE lease_id = ?))) ORDER BY created_at", [$id, $id]) as $e) $items[] = [$e['created_at'], ['fa-file-contract', 'amber', e($e['summary']), '', fdatetime($e['created_at']), $e['actor_name']]];
    foreach (all("SELECT ev.*, i.invoice_no FROM bln_invoice_events ev JOIN bln_invoices i ON i.id = ev.invoice_id WHERE i.lease_id = ? AND ev.event IN ('created','payment','credit_applied','voided','penalty','payment_reversed') ORDER BY ev.created_at", [$id]) as $e)
        $items[] = [$e['created_at'], [match ($e['event']) { 'payment' => 'fa-money-bill', 'created' => 'fa-file-invoice', 'voided' => 'fa-ban', 'penalty' => 'fa-gavel', default => 'fa-circle' }, match ($e['event']) { 'payment', 'credit_applied' => 'green', 'voided', 'penalty', 'payment_reversed' => 'red', default => 'blue' },
            e($e['invoice_no'] . ' — ' . $e['description']), $e['amount'] !== null ? money($e['amount']) . ($e['balance_after'] !== null ? ' · balance ' . money($e['balance_after']) : '') : '', fdatetime($e['created_at']), $e['actor_name']]];
    foreach (all("SELECT * FROM crm_tickets WHERE unit_id = ?", [$l['unit_id']]) as $t) if ($t['created_at'] >= $l['start_date']) $items[] = [$t['created_at'], ['fa-ticket', 'violet', 'Ticket: ' . e($t['subject']), pill($t['status']), fdatetime($t['created_at']), null]];
    usort($items, fn($a, $b) => strcmp($b[0], $a[0])); ?>
<div class="vk-panel p-6 max-w-3xl"><?= $items ? ui_timeline(array_column($items, 1)) : ui_empty('fa-route', 'No activity yet') ?></div>
<?php endif;
