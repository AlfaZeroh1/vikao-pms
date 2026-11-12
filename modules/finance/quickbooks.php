<?php
$title = 'QuickBooks export';
$subtitle = 'CSV files in QuickBooks Online import format — customers, invoices, payments, expenses and a general journal';
$types = ['customers' => ['Customers', 'fa-users', 'Every tenant & owner with contact details (import first)'], 'invoices' => ['Invoices', 'fa-file-invoice', 'One row per invoice line with product/service = bill type'],
          'payments' => ['Payments received', 'fa-money-bill', 'Receipts with the invoice they were applied to'], 'expenses' => ['Expenses', 'fa-receipt', 'Bills / expenses with category → expense account'],
          'remittances' => ['Owner remittances', 'fa-hand-holding-dollar', 'Payouts to landlords'], 'journal' => ['General journal', 'fa-scale-balanced', 'Double-entry journal of income, cash and fees for the period']];
$t = (string)($_GET['type'] ?? ''); $from = input_date('from', date('Y-m-01', strtotime('first day of last month'))); $to = input_date('to', date('Y-m-t', strtotime('last day of last month')));
$onlyNew = !empty($_GET['only_new']);
if ($t && isset($types[$t])) {
    $scope = scope_sql('property_id'); $rows = []; $head = [];
    switch ($t) {
        case 'customers':
            $head = ['Name', 'Company', 'Email', 'Phone', 'Billing Address', 'Tax Registration Number', 'Customer Type', 'Notes'];
            foreach (all("SELECT * FROM prpt_parties WHERE deleted_at IS NULL ORDER BY display_name") as $p) $rows[] = [$p['display_name'] . ' (' . $p['party_no'] . ')', $p['company_name'], $p['email'], $p['phone'], $p['postal_address'], $p['kra_pin'], $p['is_owner'] ? 'Owner' : 'Tenant', $p['party_no']];
            break;
        case 'invoices':
            $head = ['InvoiceNo', 'Customer', 'InvoiceDate', 'DueDate', 'Terms', 'Location', 'Memo', 'Item(Product/Service)', 'ItemDescription', 'ItemQuantity', 'ItemRate', 'ItemAmount', 'TaxAmount', 'Account'];
            foreach (all("SELECT i.*, l.description, l.quantity, l.unit_price, l.amount, l.tax_amount, bt.qb_item, bt.name AS bt, bt.qb_income_account, p.display_name, p.party_no, pr.name AS property, u.unit_no FROM bln_invoices i JOIN bln_invoice_lines l ON l.invoice_id = i.id JOIN cfg_bill_types bt ON bt.id = l.bill_type_id JOIN prpt_parties p ON p.id = i.party_id JOIN prpt_properties pr ON pr.id = i.property_id LEFT JOIN prpt_units u ON u.id = i.unit_id
                WHERE i.status NOT IN ('void','draft') AND i.issue_date BETWEEN ? AND ? AND " . scope_sql('i.property_id') . ($onlyNew ? ' AND i.qb_exported_at IS NULL' : '') . " ORDER BY i.issue_date, i.id, l.sort_order", [$from, $to]) as $r)
                $rows[] = [$r['invoice_no'], $r['display_name'] . ' (' . $r['party_no'] . ')', $r['issue_date'], $r['due_date'], 'Due on receipt', $r['property'], trim('Unit ' . $r['unit_no'] . ' ' . $r['period_month']), $r['qb_item'] ?: $r['bt'], $r['description'], $r['quantity'], $r['unit_price'], $r['amount'], $r['tax_amount'], $r['qb_income_account']];
            if (!empty($_GET['download'])) q("UPDATE bln_invoices SET qb_exported_at = NOW() WHERE status NOT IN ('void','draft') AND issue_date BETWEEN ? AND ? AND qb_exported_at IS NULL", [$from, $to]);
            break;
        case 'payments':
            $head = ['PaymentDate', 'Customer', 'PaymentMethod', 'DepositToAccount', 'RefNo', 'InvoiceNo', 'AmountApplied', 'Memo'];
            foreach (all("SELECT pm.*, p.display_name, p.party_no, fa.qb_account, fa.name AS account FROM fin_payments pm LEFT JOIN prpt_parties p ON p.id = pm.party_id LEFT JOIN fin_accounts fa ON fa.id = pm.account_id WHERE pm.status <> 'reversed' AND DATE(pm.paid_at) BETWEEN ? AND ?" . ($onlyNew ? ' AND pm.qb_exported_at IS NULL' : '') . " ORDER BY pm.paid_at", [$from, $to]) as $pm) {
                $al = all("SELECT i.invoice_no, SUM(a.amount) amt FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id WHERE a.payment_id = ? AND a.reversed_at IS NULL GROUP BY i.id", [$pm['id']]);
                $cust = $pm['display_name'] ? $pm['display_name'] . ' (' . $pm['party_no'] . ')' : ($pm['payer_name'] ?: 'Unidentified');
                foreach ($al as $a) $rows[] = [substr($pm['paid_at'], 0, 10), $cust, $pm['method'], $pm['qb_account'] ?: $pm['account'], $pm['reference'] ?: $pm['receipt_no'], $a['invoice_no'], $a['amt'], $pm['receipt_no']];
                $rest = round2((float)$pm['amount'] - array_sum(array_column($al, 'amt')));
                if ($rest > 0) $rows[] = [substr($pm['paid_at'], 0, 10), $cust, $pm['method'], $pm['qb_account'] ?: $pm['account'], $pm['reference'] ?: $pm['receipt_no'], '', $rest, $pm['receipt_no'] . ' — unapplied / customer credit'];
            }
            if (!empty($_GET['download'])) q("UPDATE fin_payments SET qb_exported_at = NOW() WHERE status <> 'reversed' AND DATE(paid_at) BETWEEN ? AND ? AND qb_exported_at IS NULL", [$from, $to]);
            break;
        case 'expenses':
            $head = ['BillNo', 'Vendor', 'BillDate', 'Category/Account', 'Description', 'Amount', 'Tax', 'Location', 'Billable', 'Customer'];
            foreach (all("SELECT e.*, c.name AS cat, c.qb_expense_account, v.name AS vendor, pr.name AS property, p.display_name FROM fin_expenses e JOIN fin_expense_categories c ON c.id = e.category_id LEFT JOIN fin_vendors v ON v.id = e.vendor_id LEFT JOIN prpt_properties pr ON pr.id = e.property_id LEFT JOIN prpt_parties p ON p.id = e.party_id WHERE e.status IN ('approved','paid') AND e.expense_date BETWEEN ? AND ?" . ($onlyNew ? ' AND e.qb_exported_at IS NULL' : '') . " ORDER BY e.expense_date", [$from, $to]) as $e)
                $rows[] = [$e['expense_no'], $e['vendor'] ?: 'Misc vendor', $e['expense_date'], $e['qb_expense_account'] ?: $e['cat'], $e['description'], $e['amount'], $e['tax_amount'], $e['property'], $e['chargeable_to'] !== 'agency' ? 'Yes' : 'No', $e['display_name']];
            if (!empty($_GET['download'])) q("UPDATE fin_expenses SET qb_exported_at = NOW() WHERE status IN ('approved','paid') AND expense_date BETWEEN ? AND ? AND qb_exported_at IS NULL", [$from, $to]);
            break;
        case 'remittances':
            $head = ['Date', 'Payee', 'RefNo', 'Property', 'Gross', 'ManagementFee', 'FeeVAT', 'Expenses', 'NetPaid', 'PaidFrom'];
            foreach (all("SELECT r.*, p.display_name, pr.name AS property, fa.name AS acc FROM fin_remittances r JOIN prpt_parties p ON p.id = r.party_id JOIN prpt_properties pr ON pr.id = r.property_id LEFT JOIN fin_accounts fa ON fa.id = r.paid_from_account_id WHERE r.status = 'paid' AND DATE(r.paid_at) BETWEEN ? AND ?", [$from, $to]) as $r)
                $rows[] = [substr($r['paid_at'], 0, 10), $r['display_name'], $r['payment_ref'] ?: $r['remittance_no'], $r['property'], $r['gross_collected'], $r['mgmt_fee'], $r['mgmt_fee_vat'], $r['expenses'], $r['net_payable'], $r['acc']];
            break;
        case 'journal':
            $head = ['JournalNo', 'JournalDate', 'Account', 'Debits', 'Credits', 'Description', 'Name', 'Location'];
            $n = 1;
            foreach (all("SELECT i.issue_date d, pr.name AS property, bt.qb_income_account acc, bt.name bt, SUM(l.amount) amt, SUM(l.tax_amount) tax FROM bln_invoices i JOIN bln_invoice_lines l ON l.invoice_id = i.id JOIN cfg_bill_types bt ON bt.id = l.bill_type_id JOIN prpt_properties pr ON pr.id = i.property_id WHERE i.status NOT IN ('void','draft') AND i.issue_date BETWEEN ? AND ? GROUP BY i.issue_date, pr.id, bt.id ORDER BY d", [$from, $to]) as $r) {
                $no = 'VK-INV-' . $n++; $tot = round2($r['amt'] + $r['tax']);
                $rows[] = [$no, $r['d'], 'Accounts Receivable (A/R)', $tot, '', $r['bt'] . ' invoiced', '', $r['property']];
                $rows[] = [$no, $r['d'], $r['acc'] ?: $r['bt'] . ' Income', '', $r['amt'], $r['bt'] . ' invoiced', '', $r['property']];
                if ((float)$r['tax'] > 0) $rows[] = [$no, $r['d'], 'VAT Payable', '', $r['tax'], 'VAT on ' . $r['bt'], '', $r['property']];
            }
            foreach (all("SELECT DATE(pm.paid_at) d, fa.qb_account, fa.name acc, SUM(pm.amount) amt FROM fin_payments pm JOIN fin_accounts fa ON fa.id = pm.account_id WHERE pm.status <> 'reversed' AND DATE(pm.paid_at) BETWEEN ? AND ? GROUP BY d, fa.id ORDER BY d", [$from, $to]) as $r) {
                $no = 'VK-RCPT-' . $n++;
                $rows[] = [$no, $r['d'], $r['qb_account'] ?: $r['acc'], $r['amt'], '', 'Collections', '', ''];
                $rows[] = [$no, $r['d'], 'Accounts Receivable (A/R)', '', $r['amt'], 'Collections', '', ''];
            }
            break;
    }
    if (!empty($_GET['download'])) {
        insert('fin_qb_exports', ['export_type' => $t, 'date_from' => $from, 'date_to' => $to, 'rows_count' => count($rows), 'created_by' => staff_id()]);
        audit('finance', 'qb_export', 'qb_export', null, 'QuickBooks ' . $t . ' export ' . $from . ' → ' . $to . ' (' . count($rows) . ' rows)');
        csv_download('quickbooks-' . $t . '-' . $from . '_' . $to . '.csv', $head, $rows);
    }
}
$hist = all("SELECT x.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS by_name FROM fin_qb_exports x LEFT JOIN sys_users u ON u.id = x.created_by ORDER BY x.created_at DESC LIMIT 20");
?>
<form method="get" class="vk-panel p-5 mb-5 flex flex-wrap items-end gap-3" id="qbf">
  <input type="hidden" name="r" value="finance/quickbooks">
  <?= ui_field('From', ui_input('from', $from, 'date'), 'w-44') ?><?= ui_field('To', ui_input('to', $to, 'date'), 'w-44') ?>
  <label class="vk-switch pb-3"><input type="checkbox" name="only_new" value="1" <?= $onlyNew ? 'checked' : '' ?>>Only items not exported before</label>
</form>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 mb-8">
  <?php foreach ($types as $k => [$l, $ic, $d]): ?>
  <div class="vk-panel p-5 flex flex-col">
    <div class="flex items-center gap-3"><span class="grid h-11 w-11 place-items-center rounded-xl bg-emerald-500/10 text-emerald-600"><i class="fa-solid <?= $ic ?>"></i></span><div><p class="font-bold"><?= $l ?></p><p class="text-xs text-muted"><?= $d ?></p></div></div>
    <div class="mt-4 flex gap-2"><a class="vk-btn-ghost !py-2 text-sm flex-1 justify-center qb-link" data-type="<?= $k ?>" data-preview="1" href="#"><i class="fa-solid fa-eye"></i>Preview</a><a class="vk-btn-accent !py-2 text-sm flex-1 justify-center qb-link" data-type="<?= $k ?>" href="#"><i class="fa-solid fa-download"></i>CSV</a></div>
  </div>
  <?php endforeach; ?>
</div>
<?php if ($t && isset($types[$t]) && empty($_GET['download'])): ?>
<div class="vk-panel overflow-hidden mb-8"><div class="px-5 py-3.5 border-b border-line font-bold">Preview — <?= $types[$t][0] ?> (<?= count($rows) ?> rows)</div><div class="overflow-x-auto max-h-[60vh]"><table class="vk-table"><thead><tr><?php foreach ($head as $h) echo '<th>' . e($h) . '</th>'; ?></tr></thead><tbody>
  <?php foreach (array_slice($rows, 0, 200) as $r): ?><tr><?php foreach ($r as $c) echo '<td class="text-xs whitespace-nowrap">' . e((string)$c) . '</td>'; ?></tr><?php endforeach; ?></tbody></table></div><?= $rows ? '' : ui_empty('fa-file-export', 'Nothing to export for this period') ?></div>
<?php endif; ?>
<h3 class="font-bold mb-3">Export history</h3>
<div class="vk-panel overflow-hidden"><table class="vk-table"><thead><tr><th>Type</th><th>Period</th><th class="num">Rows</th><th>By</th><th>When</th></tr></thead><tbody>
<?php foreach ($hist as $h): ?><tr><td><?= e($types[$h['export_type']][0] ?? $h['export_type']) ?></td><td class="text-sm"><?= fdate($h['date_from']) ?> – <?= fdate($h['date_to']) ?></td><td class="num"><?= (int)$h['rows_count'] ?></td><td><?= e($h['by_name']) ?></td><td class="text-xs"><?= fdatetime($h['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table><?= $hist ? '' : ui_empty('fa-clock-rotate-left', 'No exports yet') ?></div>
<?php page_script('<script>$(".qb-link").on("click",function(e){e.preventDefault();const q=$("#qbf").serialize()+"&type="+$(this).data("type")+($(this).data("preview")?"":"&download=1");location.href="?"+q})</script>');
