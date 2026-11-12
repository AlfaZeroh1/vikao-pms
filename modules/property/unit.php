<?php
require_once __DIR__ . '/_partials.php';
$id = (int)($_GET['id'] ?? 0);
$u = one("SELECT u.*, pr.name AS property, pr.id AS pid, b.code AS block_code, b.name AS block_name, f.name AS floor_name FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id
          LEFT JOIN prpt_blocks b ON b.id = u.block_id LEFT JOIN prpt_floors f ON f.id = u.floor_id WHERE u.id = ? AND " . scope_sql('u.property_id'), [$id]);
if (!$u) { echo ui_empty('fa-door-open', 'Unit not found'); return; }
$title = 'Unit ' . $u['unit_no']; $subtitle = e($u['property']) . ($u['block_name'] ? ' · ' . e($u['block_name']) : '') . ($u['floor_name'] ? ' · ' . e($u['floor_name']) : '') . ' · ' . pill($u['status']);
$crumbs = [['Properties', page('property/properties')], [$u['property'], page('property/property', ['id' => $u['pid'], 'tab' => 'structure'])]];
$tab = $_GET['tab'] ?? 'overview';
$lease = unit_active_lease($id);
$owners = unit_owners($id);
$a = [];
if (can('billing.leases.manage') && !$lease) $a[] = '<a class="vk-btn-accent" href="' . e(page('billing/lease-new', ['unit_id' => $id])) . '"><i class="fa-solid fa-file-contract"></i>Open account</a>';
if ($lease) $a[] = '<a class="vk-btn-ghost" href="' . e(page('billing/lease', ['id' => $lease['id']])) . '"><i class="fa-solid fa-file-contract"></i>' . e($lease['lease_no']) . '</a>';
if (can('property.properties.manage')) $a[] = '<button class="vk-btn-ghost" data-crud-edit="#m-unit" data-row=\'' . e(json_encode($u)) . '\' data-title="Edit unit"><i class="fa-solid fa-pen"></i>Edit</button>';
if (can('ecommerce.official') && in_array($u['purpose'], ['sale', 'rent', 'rent_and_sale'], true)) $a[] = '<a class="vk-btn-ghost" href="' . e(page('ecommerce/push', ['unit_id' => $id])) . '"><i class="fa-solid fa-store"></i>' . ($u['ecom_listing_uuid'] ? 'On marketplace' : 'Publish to vikao.io') . '</a>';
$a[] = '<a class="vk-btn-ghost" href="' . e(page('finance/statement', ['type' => 'unit', 'id' => $id])) . '"><i class="fa-solid fa-file-lines"></i>Statement</a>';
$actions = implode('', $a);
echo ui_tabs(['overview' => ['Overview', 'fa-gauge'], 'journey' => ['Journey', 'fa-route'], 'billing' => ['Invoices & payments', 'fa-file-invoice'], 'readings' => ['Meters & readings', 'fa-gauge-high'], 'documents' => ['Documents', 'fa-folder-open']], $tab, 'tab', ['id' => $id]);

if ($tab === 'overview'):
    $tenants = $lease ? all("SELECT p.*, lp.role, lp.is_primary FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = ? AND lp.role <> 'owner' ORDER BY lp.is_primary DESC", [$lease['id']]) : [];
    $arrears = (float)val("SELECT COALESCE(SUM(balance),0) FROM bln_invoices WHERE unit_id = ? AND status IN ('issued','partially_paid','overdue')", [$id]);
    $parking = all("SELECT * FROM prpt_parking_slots WHERE unit_id = ?", [$id]);
    $meters = all("SELECT m.*, bt.name AS bt, bt.unit_label FROM prpt_meters m JOIN cfg_bill_types bt ON bt.id = m.bill_type_id WHERE m.unit_id = ?", [$id]);
    $charges = $lease ? all("SELECT c.*, bt.name, p.display_name AS payer FROM bln_lease_charges c JOIN cfg_bill_types bt ON bt.id = c.bill_type_id JOIN prpt_parties p ON p.id = c.bill_to_party_id WHERE c.lease_id = ? AND c.is_active = 1", [$lease['id']]) : [];
?>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <?= ui_stat('fa-house-chimney', 'Asking rent', $u['rent_amount'] ? money($u['rent_amount']) : '—', 'accent') ?>
    <?= ui_stat('fa-building-shield', 'Service charge', $u['service_charge'] ? money($u['service_charge']) : '—', 'blue') ?>
    <?= ui_stat('fa-triangle-exclamation', 'Outstanding', money($arrears), $arrears > 0 ? 'red' : 'green') ?>
    <?= ui_stat('fa-tag', 'Sale price', $u['sale_price'] ? money($u['sale_price']) : '—', 'violet') ?>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="vk-panel p-5">
      <h3 class="font-bold mb-3">Unit details</h3>
      <dl class="space-y-2 text-sm"><?php foreach ([['Type', ucfirst($u['unit_type'])], ['Usage', ucfirst($u['usage_class'])], ['Purpose', ucwords(str_replace('_', ' ', $u['purpose']))], ['Bedrooms / baths', ($u['bedrooms'] ?? '—') . ' / ' . ($u['bathrooms'] ?? '—')],
        ['Size', $u['size_sqm'] ? num($u['size_sqm'], 1) . ' m²' : '—'], ['Furnishing', ucwords(str_replace('_', ' ', (string)$u['furnishing'])) ?: '—'], ['Deposit', num($u['deposit_months'], 1) . ' month(s)']] as [$k, $v]): ?>
        <div class="flex justify-between border-b border-line/60 pb-1.5"><dt class="text-muted"><?= $k ?></dt><dd class="font-semibold"><?= e($v) ?></dd></div><?php endforeach; ?></dl>
      <?php if ($parking): ?><p class="mt-4 text-sm font-bold">Parking</p><div class="flex flex-wrap gap-1.5 mt-1"><?php foreach ($parking as $s) echo '<span class="vk-chip"><i class="fa-solid fa-square-parking"></i>' . e($s['code']) . '</span>'; ?></div><?php endif; ?>
    </div>
    <div class="vk-panel p-5">
      <h3 class="font-bold mb-3">Owner(s)</h3>
      <?php foreach ($owners as $o): ?><a class="flex items-center gap-3 py-1.5 hover:text-accent" href="<?= e(page('property/party', ['id' => $o['party_id']])) ?>"><?= ui_avatar($o['display_name']) ?><span class="text-sm font-semibold"><?= e($o['display_name']) ?></span><span class="ml-auto text-xs text-muted"><?= num($o['share_pct'], 1) ?>% · <?= e($o['scope']) ?></span></a><?php endforeach; ?>
      <?php if (!$owners) echo '<p class="text-sm text-muted">No owner recorded.</p>'; if (can('property.ownership.manage')): ?><button class="mt-3 vk-btn-ghost !py-1.5 text-xs" data-modal-open="#m-own"><i class="fa-solid fa-plus"></i>Add owner</button><?php endif; ?>
      <h3 class="font-bold mt-5 mb-3">Occupant(s)</h3>
      <?php foreach ($tenants as $t): ?><a class="flex items-center gap-3 py-1.5 hover:text-accent" href="<?= e(page('property/party', ['id' => $t['id']])) ?>"><?= ui_avatar($t['display_name']) ?><span class="text-sm font-semibold"><?= e($t['display_name']) ?></span><span class="ml-auto text-xs text-muted"><?= e($t['role']) ?></span></a><?php endforeach; ?>
      <?php if (!$tenants) echo '<p class="text-sm text-muted">' . ($lease ? 'Owner account — no tenant.' : 'No active account.') . '</p>'; ?>
    </div>
    <div class="vk-panel p-5">
      <h3 class="font-bold mb-3">Bills followed up</h3>
      <?php foreach ($charges as $c): ?><div class="flex items-center justify-between py-1.5 text-sm border-b border-line/60"><span><?= e($c['name']) ?><span class="block text-xs text-muted">→ <?= e($c['payer']) ?></span></span><b><?= $c['billing_method'] === 'metered' ? 'metered' : money($c['amount'], true, true) ?></b></div><?php endforeach; ?>
      <?php if (!$charges) echo '<p class="text-sm text-muted">Open an account to start billing this unit.</p>'; ?>
      <h3 class="font-bold mt-5 mb-2">Meters</h3>
      <?php foreach ($meters as $m): ?><div class="flex justify-between text-sm py-1"><span><i class="fa-solid fa-gauge text-muted mr-1"></i><?= e($m['bt']) ?> · <?= e($m['serial_no']) ?></span><b><?= num(meter_last_reading((int)$m['id']), 1) ?> <?= e($m['unit_label']) ?></b></div><?php endforeach; ?>
      <?php if (!$meters) echo '<p class="text-sm text-muted">No meters.</p>'; ?>
    </div>
  </div>
<?php elseif ($tab === 'journey'):
    $items = [];
    foreach (all("SELECT s.*, CONCAT(x.first_name, ' ', COALESCE(x.last_name,'')) AS who FROM prpt_unit_status_log s LEFT JOIN sys_users x ON x.id = s.user_id WHERE s.unit_id = ?", [$id]) as $s)
        $items[$s['created_at'] . 's' . $s['id']] = ['fa-arrow-right-arrow-left', STATUS_TONE[$s['to_status']] ?? 'slate', 'Status: ' . e(str_replace('_', ' ', (string)$s['from_status'])) . ' → <b>' . e(str_replace('_', ' ', $s['to_status'])) . '</b>', e($s['reason']), fdatetime($s['created_at']), trim((string)$s['who'])];
    foreach (all("SELECT l.*, (SELECT GROUP_CONCAT(p.display_name SEPARATOR ', ') FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = l.id AND lp.role <> 'owner') AS who FROM bln_leases l WHERE l.unit_id = ?", [$id]) as $l) {
        $items[$l['start_date'] . ' 00:00:00l' . $l['id']] = ['fa-file-contract', 'green', 'Account <a class="text-accent" href="' . e(page('billing/lease', ['id' => $l['id']])) . '">' . e($l['lease_no']) . '</a> opened — ' . e(str_replace('_', ' ', $l['occupancy_type'])), e($l['who']) . ' · rent ' . money($l['rent_amount']), fdate($l['start_date']), null];
        if ($l['status'] === 'ended' || $l['status'] === 'terminated') $items[$l['end_date'] . ' 23:59:59e' . $l['id']] = ['fa-door-closed', 'slate', 'Account ' . e($l['lease_no']) . ' ' . $l['status'], e($l['termination_reason']), fdate($l['end_date']), null];
    }
    foreach (all("SELECT o.*, p.display_name FROM prpt_ownerships o JOIN prpt_parties p ON p.id = o.party_id WHERE o.unit_id = ?", [$id]) as $o)
        $items[$o['start_date'] . ' 00:00:01o' . $o['id']] = ['fa-key', 'violet', e($o['display_name']) . ' became owner (' . num($o['share_pct'], 1) . '%)', 'Acquired by ' . e($o['acquisition']), fdate($o['start_date']), null];
    krsort($items); ?>
  <div class="vk-panel p-6 max-w-3xl"><?= $items ? ui_timeline(array_values($items)) : ui_empty('fa-route', 'No history yet') ?></div>
<?php elseif ($tab === 'billing'):
    $inv = all("SELECT i.*, p.display_name FROM bln_invoices i JOIN prpt_parties p ON p.id = i.party_id WHERE i.unit_id = ? ORDER BY i.issue_date DESC, i.id DESC LIMIT 100", [$id]);
    $pays = all("SELECT DISTINCT pm.* FROM fin_payments pm LEFT JOIN fin_allocations a ON a.payment_id = pm.id LEFT JOIN bln_invoices i ON i.id = a.invoice_id WHERE pm.unit_id = ? OR i.unit_id = ? ORDER BY pm.paid_at DESC LIMIT 50", [$id, $id]); ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Invoices</div>
      <table class="vk-table"><thead><tr><th>Invoice</th><th>Billed to</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th></tr></thead><tbody>
      <?php foreach ($inv as $i): ?><tr data-href="<?= e(page('billing/invoice', ['id' => $i['id']])) ?>"><td><b><?= e($i['invoice_no']) ?></b><span class="block text-xs text-muted"><?= fdate($i['issue_date']) ?></span></td><td class="text-sm"><?= e($i['display_name']) ?></td><td><?= pill($i['status']) ?></td><td class="num"><?= money($i['total'], false) ?></td><td class="num"><?= ui_money_cell($i['balance'], true) ?></td></tr><?php endforeach; ?>
      </tbody></table><?= $inv ? '' : ui_empty('fa-file-invoice', 'No invoices') ?></div>
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Payments</div>
      <table class="vk-table"><thead><tr><th>Receipt</th><th>Reference</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
      <?php foreach ($pays as $pm): ?><tr data-href="<?= e(page('finance/consume', ['payment' => $pm['uuid']])) ?>"><td><b><?= e($pm['receipt_no']) ?></b><span class="block text-xs text-muted"><?= fdatetime($pm['paid_at']) ?></span></td><td class="text-xs"><?= e($pm['reference'] ?: $pm['method']) ?></td><td><?= pill($pm['status']) ?></td><td class="num"><?= money($pm['amount'], false) ?></td></tr><?php endforeach; ?>
      </tbody></table><?= $pays ? '' : ui_empty('fa-money-bill', 'No payments') ?></div>
  </div>
<?php elseif ($tab === 'readings'):
    $rd = all("SELECT r.*, m.serial_no, bt.name AS bt, bt.unit_label FROM prpt_meter_readings r JOIN prpt_meters m ON m.id = r.meter_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id WHERE m.unit_id = ? ORDER BY r.reading_date DESC, r.id DESC LIMIT 60", [$id]); ?>
  <div class="vk-panel overflow-hidden"><table class="vk-table"><thead><tr><th>Date</th><th>Meter</th><th class="num">Previous</th><th class="num">Current</th><th class="num">Consumption</th><th>Status</th><th>Photo</th></tr></thead><tbody>
    <?php foreach ($rd as $r): ?><tr><td><?= fdate($r['reading_date']) ?></td><td><?= e($r['bt']) ?><span class="block text-xs text-muted"><?= e($r['serial_no']) ?> · <?= e($r['source']) ?></span></td><td class="num"><?= num($r['previous_reading'], 2) ?></td><td class="num"><?= num($r['current_reading'], 2) ?></td>
      <td class="num font-bold"><?= num($r['consumption'], 2) ?> <?= e($r['unit_label']) ?><?= $r['anomaly_flag'] ? '<span class="block text-[11px] text-rose-600">⚠ ' . e($r['anomaly_flag']) . '</span>' : '' ?></td><td><?= pill($r['status']) ?></td>
      <td><?= $r['photo_path'] ? '<a href="' . e(file_url($r['photo_path'])) . '" target="_blank" class="text-accent"><i class="fa-solid fa-image"></i></a>' : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table><?= $rd ? '' : ui_empty('fa-gauge', 'No readings yet') ?></div>
<?php elseif ($tab === 'documents'): ?>
  <div class="vk-panel overflow-hidden"><div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold">Unit documents</h3><?php if (can('property.documents.manage')): ?><button class="ml-auto vk-btn-accent !py-2 text-sm" data-modal-open="#m-doc"><i class="fa-solid fa-upload"></i>Upload</button><?php endif; ?></div>
    <?= documents_table("(d.owner_type = 'unit' AND d.owner_id = :id) OR (d.owner_type = 'lease' AND d.owner_id IN (SELECT id FROM bln_leases WHERE unit_id = :id))", ['id' => $id]) ?></div>
  <?= document_modal_html('unit', $id) ?>
<?php endif;
echo unit_modal_html((int)$u['pid']);
if (can('property.ownership.manage')) echo ownership_modal_html((int)$u['pid'], null, $id);
