<?php
$qs = trim((string)($_GET['q'] ?? '')); $title = 'Search'; $subtitle = $qs ? 'Results for “' . e($qs) . '”' : null;
$like = '%' . $qs . '%'; $groups = [];
if (mb_strlen($qs) >= 2) {
    if (can('property.parties.view')) $groups['People'] = array_map(fn($r) => [page('property/party', ['id' => $r['id']]), 'fa-user', $r['display_name'], trim($r['party_no'] . ' · ' . $r['phone'] . ' · ' . $r['email'], ' ·')],
        all("SELECT * FROM prpt_parties WHERE deleted_at IS NULL AND (display_name LIKE ? OR phone LIKE ? OR email LIKE ? OR id_number LIKE ? OR party_no LIKE ?) LIMIT 15", [$like, $like, $like, $like, $like]));
    if (can('property.properties.view')) {
        $groups['Units'] = array_map(fn($r) => [page('property/unit', ['id' => $r['id']]), 'fa-door-open', $r['unit_no'] . ' · ' . $r['property'], ucfirst($r['status'])],
            all("SELECT u.id, u.unit_no, u.status, p.name AS property FROM prpt_units u JOIN prpt_properties p ON p.id = u.property_id WHERE u.deleted_at IS NULL AND u.unit_no LIKE ? AND " . scope_sql('u.property_id') . " LIMIT 15", [$like]));
        $groups['Properties'] = array_map(fn($r) => [page('property/property', ['id' => $r['id']]), 'fa-building', $r['name'], $r['code'] . ' · ' . $r['area']],
            all("SELECT * FROM prpt_properties WHERE deleted_at IS NULL AND (name LIKE ? OR code LIKE ?) AND " . scope_sql('id') . " LIMIT 10", [$like, $like]));
    }
    if (can('billing.invoices.view')) $groups['Invoices'] = array_map(fn($r) => [page('billing/invoice', ['id' => $r['id']]), 'fa-file-invoice', $r['invoice_no'] . ' · ' . money($r['total']), $r['display_name'] . ' · ' . $r['status']],
        all("SELECT i.*, p.display_name FROM bln_invoices i JOIN prpt_parties p ON p.id = i.party_id WHERE i.invoice_no LIKE ? LIMIT 10", [$like]));
    if (can('billing.leases.view')) $groups['Accounts'] = array_map(fn($r) => [page('billing/lease', ['id' => $r['id']]), 'fa-file-contract', $r['lease_no'], $r['unit_no'] . ' · ' . $r['status']],
        all("SELECT l.*, u.unit_no FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id WHERE l.lease_no LIKE ? LIMIT 10", [$like]));
    if (can('finance.payments.view')) $groups['Payments'] = array_map(fn($r) => [page('finance/consume', ['payment' => $r['uuid']]), 'fa-money-bill', $r['receipt_no'] . ' · ' . money($r['amount']), ($r['reference'] ?: '') . ' · ' . ($r['payer_name'] ?: '') . ' · ' . $r['status']],
        all("SELECT * FROM fin_payments WHERE receipt_no LIKE ? OR reference LIKE ? OR payer_phone LIKE ? OR payer_name LIKE ? ORDER BY paid_at DESC LIMIT 10", [$like, $like, $like, $like]));
    if (can('crm.tickets.view')) $groups['Tickets'] = array_map(fn($r) => [page('crm/ticket', ['id' => $r['id']]), 'fa-ticket', $r['ticket_no'] . ' · ' . $r['subject'], $r['status']],
        all("SELECT * FROM crm_tickets WHERE ticket_no LIKE ? OR subject LIKE ? LIMIT 10", [$like, $like]));
    if (can('shortstay.view')) $groups['Bookings'] = array_map(fn($r) => [page('shortstay/booking', ['id' => $r['id']]), 'fa-suitcase-rolling', $r['booking_no'], fdate($r['check_in']) . ' → ' . fdate($r['check_out'])],
        all("SELECT * FROM sst_bookings WHERE booking_no LIKE ? OR channel_ref LIKE ? LIMIT 10", [$like, $like]));
    if (can('sales.leads.manage')) $groups['Leads'] = array_map(fn($r) => [page('sales/lead', ['id' => $r['id']]), 'fa-user-plus', $r['full_name'], $r['lead_no'] . ' · ' . $r['phone']],
        all("SELECT * FROM sls_leads WHERE full_name LIKE ? OR phone LIKE ? OR email LIKE ? OR lead_no LIKE ? LIMIT 10", [$like, $like, $like, $like]));
    // exact jumps
    $all = array_merge(...array_values($groups));
    if (count($all) === 1) redirect($all[0][0]);
}
?>
<form method="get" class="mb-5 relative max-w-2xl"><input type="hidden" name="r" value="dashboard/search"><i class="fa-solid fa-magnifying-glass absolute left-4 top-1/2 -translate-y-1/2 text-muted"></i><input name="q" value="<?= e($qs) ?>" class="vk-input !pl-11" placeholder="Search units, tenants, invoices, receipts, tickets…" <?= $qs ? '' : 'autofocus' ?>></form>
<?php if (mb_strlen($qs) < 2): echo '<div class="vk-panel">' . ui_empty('fa-magnifying-glass', 'Type at least 2 characters') . '</div>'; return; endif; ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
  <?php $any = false; foreach ($groups as $g => $items): if (!$items) continue; $any = true; ?>
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3 border-b border-line font-bold text-sm"><?= e($g) ?> <span class="text-muted font-normal"><?= count($items) ?></span></div>
      <ul class="divide-y divide-line/60"><?php foreach ($items as [$href, $icon, $label, $sub]): ?>
        <li><a href="<?= e($href) ?>" class="flex items-center gap-3 px-5 py-3 hover:bg-surface2/50"><i class="fa-solid <?= $icon ?> text-accent w-5"></i><span class="min-w-0"><span class="block font-semibold text-sm truncate"><?= e($label) ?></span><span class="block text-xs text-muted truncate"><?= e($sub) ?></span></span></a></li>
      <?php endforeach; ?></ul></div>
  <?php endforeach; if (!$any) echo '<div class="vk-panel lg:col-span-2">' . ui_empty('fa-magnifying-glass', 'Nothing found', 'Try a unit number, phone number, invoice number or M-Pesa code.') . '</div>'; ?>
</div>
