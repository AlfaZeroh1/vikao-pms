<?php
$r = one("SELECT r.*, pt.display_name, pt.email, pt.phone, pt.party_no, pr.name AS property, fa.name AS paid_from FROM fin_remittances r JOIN prpt_parties pt ON pt.id = r.party_id JOIN prpt_properties pr ON pr.id = r.property_id LEFT JOIN fin_accounts fa ON fa.id = r.paid_from_account_id WHERE r.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$r) { echo ui_empty('fa-hand-holding-dollar', 'Remittance not found'); return; }
$b = json_decode((string)$r['breakdown'], true) ?: ['lines' => [], 'expenses' => [], 'fee_label' => ''];
$bank = one("SELECT * FROM prpt_party_bank_accounts WHERE party_id = ? ORDER BY is_default DESC LIMIT 1", [$r['party_id']]);
$body = function () use ($r, $b, $bank) { ob_start(); ?>
  <div class="grid2"><div><h1>OWNER REMITTANCE</h1><p class="muted"><?= e($r['remittance_no']) ?> · <?= fdate($r['period_start']) ?> – <?= fdate($r['period_end']) ?></p><p><b><?= e($r['display_name']) ?></b><br><?= e($r['party_no']) ?> · <?= e($r['phone']) ?></p></div>
    <div class="right"><p><b><?= e($r['property']) ?></b></p><p>Status: <span class="badge"><?= e(strtoupper($r['status'])) ?></span><?= $r['paid_at'] ? '<br>Paid ' . fdate($r['paid_at']) . ($r['payment_ref'] ? ' · ' . e($r['payment_ref']) : '') : '' ?></p>
      <?php if ($bank): ?><p class="muted">Pay to: <?= e(strtoupper($bank['method'])) ?> <?= e($bank['bank_name']) ?><br><?= e($bank['account_name']) ?> · <?= e($bank['account_number']) ?></p><?php endif; ?></div></div>
  <h2>Income collected on your units</h2>
  <table><thead><tr><th>Unit</th><th>Bill</th><th class="num">Collected</th><th class="num">Your share</th><th class="num">Amount</th></tr></thead><tbody>
  <?php foreach ($b['lines'] as $l): ?><tr><td><?= e($l['unit']) ?></td><td><?= e($l['bill']) ?></td><td class="num"><?= money($l['collected'], false) ?></td><td class="num"><?= num($l['share_pct'], 2) ?>%</td><td class="num"><?= money($l['owner_amount'], false) ?></td></tr><?php endforeach; ?>
  <?php if (!$b['lines']) echo '<tr><td colspan="5" class="muted">No owner income collected in this period.</td></tr>'; ?>
  <tr class="tot"><td colspan="4">Gross collected</td><td class="num"><?= money($r['gross_collected']) ?></td></tr></tbody></table>
  <?php if ($b['expenses']): ?><h2>Expenses charged to you</h2><table><tbody><?php foreach ($b['expenses'] as $x): ?><tr><td><?= fdate($x['date']) ?></td><td><?= e($x['description']) ?></td><td class="num"><?= money($x['amount'], false) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <h2>Summary</h2>
  <table><tbody>
    <tr><td>Gross collected</td><td class="num"><?= money($r['gross_collected']) ?></td></tr>
    <tr><td>Management fee <span class="muted">(<?= e($b['fee_label']) ?>)</span></td><td class="num">−<?= money($r['mgmt_fee']) ?></td></tr>
    <tr><td>VAT on management fee</td><td class="num">−<?= money($r['mgmt_fee_vat']) ?></td></tr>
    <tr><td>Expenses</td><td class="num">−<?= money($r['expenses']) ?></td></tr>
    <?php if ((float)$r['withholding_tax'] > 0): ?><tr><td>Withholding tax</td><td class="num">−<?= money($r['withholding_tax']) ?></td></tr><?php endif; ?>
    <?php if ((float)$r['adjustments'] != 0.0): ?><tr><td>Adjustments</td><td class="num"><?= money($r['adjustments']) ?></td></tr><?php endif; ?>
    <tr class="tot"><td>Net payable to you</td><td class="num big"><?= money($r['net_payable']) ?></td></tr>
  </tbody></table>
<?php return ob_get_clean(); };
if (!empty($_GET['print'])) { $layout = 'print'; $title = 'Remittance ' . $r['remittance_no']; echo $body(); return; }
$title = 'Remittance ' . $r['remittance_no'];
$subtitle = e($r['display_name'] . ' · ' . $r['property']) . ' · ' . pill($r['status']);
$crumbs = [['Owner remittances', page('finance/remittances')]];
$a = ['<a class="vk-btn-ghost" target="_blank" href="' . e(page('finance/remittance', ['id' => $r['id'], 'print' => 1])) . '"><i class="fa-solid fa-print"></i>Print / PDF</a>'];
if ($r['status'] === 'draft') { $a[] = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'finance/remittance-status'])) . '" data-payload=\'{"id":' . $r['id'] . ',"status":"adjust"}\' data-prompt="Adjustment amount (+ adds, − deducts)" data-prompt-type="number" data-prompt-name="adjustments"><i class="fa-solid fa-plus-minus"></i>Adjust</button>';
    if (can('finance.remittances.approve')) $a[] = '<button class="vk-btn-accent" data-action="' . e(url('api.php', ['r' => 'finance/remittance-status'])) . '" data-payload=\'{"id":' . $r['id'] . ',"status":"approved"}\' data-confirm="Approve this remittance for payment?" data-icon="question"><i class="fa-solid fa-check"></i>Approve</button>'; }
if ($r['status'] === 'approved' && can('finance.remittances.approve')) $a[] = '<button class="vk-btn-accent" data-modal-open="#m-paid"><i class="fa-solid fa-money-check-dollar"></i>Mark paid</button>';
if (in_array($r['status'], ['draft', 'approved'], true)) $a[] = '<button class="vk-btn-ghost !text-rose-600" data-action="' . e(url('api.php', ['r' => 'finance/remittance-status'])) . '" data-payload=\'{"id":' . $r['id'] . ',"status":"cancelled"}\' data-confirm="Cancel this remittance? Its expenses become available again."><i class="fa-solid fa-ban"></i>Cancel</button>';
$actions = implode('', $a);
?>
<div class="vk-doc max-w-4xl border border-line overflow-x-auto"><?= $body() ?></div>
<?= ui_modal('m-paid', 'Mark remittance paid', '<input type="hidden" name="id" value="' . $r['id'] . '"><input type="hidden" name="status" value="paid"><p class="text-sm">Pay <b>' . money($r['net_payable']) . '</b> to ' . e($r['display_name']) . ($bank ? ' — ' . e(strtoupper($bank['method']) . ' ' . $bank['bank_name'] . ' ' . $bank['account_number']) : '') . '</p>'
  . ui_field('Paid from', ui_select('paid_from_account_id', ui_options(all("SELECT id, name FROM fin_accounts WHERE is_active = 1"), 'id', 'name'))) . ui_field('Payment reference', ui_input('payment_ref', '', 'text', 'placeholder="Bank / M-Pesa B2C ref"')) . '<p class="text-xs text-muted">The owner is emailed and notified in their portal.</p>', url('api.php', ['r' => 'finance/remittance-status']), 'Mark paid', 'max-w-md', 'fa-money-check-dollar') ?>
