<?php
require_once __DIR__ . '/_partials.php';
$id = (int)($_GET['id'] ?? 0);
$p = one("SELECT p.*, a.status AS portal_status, a.username, a.activation_sent_at, a.activated_at, a.last_login_at, a.last_seen_at, a.app_installed_at FROM prpt_parties p LEFT JOIN prt_accounts a ON a.party_id = p.id WHERE p.id = ? AND p.deleted_at IS NULL", [$id]);
if (!$p) { echo ui_empty('fa-user', 'Person not found'); return; }
$title = $p['display_name'];
$roles = array_filter([$p['is_owner'] ? 'Home owner' : null, $p['is_tenant'] ? 'Tenant' : null, $p['is_guest'] ? 'Guest' : null, $p['is_buyer'] ? 'Buyer' : null]);
$subtitle = e($p['party_no']) . ' · ' . e(implode(' · ', $roles) ?: 'Contact');
$crumbs = [['Owners & tenants', page('property/parties')]];
$tab = $_GET['tab'] ?? 'overview';
$balance = party_balance($id);
$owned = party_owned_units($id);
$leases = all("SELECT l.*, u.unit_no, u.uuid AS unit_uuid, pr.name AS property, lp.role FROM bln_lease_parties lp JOIN bln_leases l ON l.id = lp.lease_id JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE lp.party_id = ? ORDER BY l.status IN ('active','notice') DESC, l.start_date DESC", [$id]);
$a = [];
if (can('property.parties.manage')) $a[] = '<button class="vk-btn-ghost" data-crud-edit="#m-party" data-row=\'' . e(json_encode($p)) . '\' data-title="Edit ' . e($p['display_name']) . '"><i class="fa-solid fa-pen"></i>Edit</button>';
$a[] = '<a class="vk-btn-ghost" href="' . e(page('finance/statement', ['type' => 'party', 'id' => $id])) . '"><i class="fa-solid fa-file-lines"></i>Statement</a>';
if ($p['is_owner']) $a[] = '<a class="vk-btn-ghost" href="' . e(page('finance/statement', ['type' => 'landlord', 'id' => $id])) . '"><i class="fa-solid fa-hand-holding-dollar"></i>Landlord statement</a>';
if (can('comms.send')) $a[] = '<a class="vk-btn-ghost" href="' . e(page('comms/compose', ['party_id' => $id])) . '"><i class="fa-solid fa-paper-plane"></i>Message</a>';
if (can('finance.payments.create')) $a[] = '<a class="vk-btn-accent" href="' . e(page('finance/payments', ['new' => 1, 'party_id' => $id])) . '"><i class="fa-solid fa-money-bill"></i>Record payment</a>';
$actions = implode('', $a);
echo ui_tabs(['overview' => ['Overview', 'fa-id-card'], 'units' => ['Units & accounts', 'fa-door-open', count($owned) + count($leases)], 'billing' => ['Invoices & payments', 'fa-file-invoice'], 'portal' => ['Self-service portal', 'fa-mobile-screen'], 'documents' => ['Documents', 'fa-folder-open'], 'activity' => ['Tickets & messages', 'fa-comments']], $tab, 'tab', ['id' => $id]);

if ($tab === 'overview'):
    $credits = (float)val("SELECT COALESCE(SUM(remaining),0) FROM fin_credits WHERE party_id = ? AND status = 'open'", [$id]);
    $paid12 = (float)val("SELECT COALESCE(SUM(amount),0) FROM fin_payments WHERE party_id = ? AND status <> 'reversed' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)", [$id]);
    $banks = all("SELECT * FROM prpt_party_bank_accounts WHERE party_id = ?", [$id]); ?>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <?= ui_stat('fa-scale-balanced', $balance >= 0 ? 'Balance owed' : 'In credit', money(abs($balance)), $balance > 0 ? 'red' : 'green') ?>
    <?= ui_stat('fa-piggy-bank', 'Prepaid / overpaid credit', money($credits), 'violet') ?>
    <?= ui_stat('fa-money-bill-trend-up', 'Paid last 12 months', money($paid12, true, true), 'green') ?>
    <?= ui_stat('fa-door-open', 'Units', (string)(count($owned) + count(array_filter($leases, fn($l) => in_array($l['status'], ['active', 'notice'], true) && $l['role'] !== 'owner'))), 'blue', count($owned) . ' owned') ?>
  </div>
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="vk-panel p-5 lg:col-span-2">
      <h3 class="font-bold mb-4">Bio data</h3>
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-2.5 text-sm">
        <?php foreach ([['Type', ucfirst($p['party_type'])], ['ID', trim(str_replace('_', ' ', (string)$p['id_type']) . ' ' . $p['id_number']) ?: '—'], ['KRA PIN', $p['kra_pin'] ?: '—'], ['Phone', $p['phone'] ?: '—'], ['Alt. phone', $p['alt_phone'] ?: '—'], ['Email', $p['email'] ?: '—'],
                        ['Date of birth', fdate($p['date_of_birth'])], ['Gender', ucfirst((string)$p['gender']) ?: '—'], ['Nationality', $p['nationality'] ?: '—'], ['Occupation', trim($p['occupation'] . ($p['employer'] ? ' · ' . $p['employer'] : '')) ?: '—'],
                        ['Postal address', $p['postal_address'] ?: '—'], ['Physical address', $p['physical_address'] ?: '—'], ['Next of kin', trim($p['next_of_kin_name'] . ' ' . $p['next_of_kin_phone'] . ($p['next_of_kin_relation'] ? ' (' . $p['next_of_kin_relation'] . ')' : '')) ?: '—'], ['Prefers', ucfirst($p['preferred_channel'])]] as [$k, $v]): ?>
          <div class="flex justify-between gap-3 border-b border-line/60 pb-1.5"><dt class="text-muted"><?= e($k) ?></dt><dd class="font-semibold text-right"><?= e($v) ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <?php if ($p['notes']): ?><p class="mt-4 text-sm"><?= nl2br(e($p['notes'])) ?></p><?php endif; ?>
    </div>
    <div class="vk-panel p-5">
      <div class="flex items-center mb-3"><h3 class="font-bold">Payout accounts</h3><?php if (can('property.parties.manage')): ?><button class="ml-auto vk-btn-ghost !py-1 !px-2 text-xs" data-modal-open="#m-bank"><i class="fa-solid fa-plus"></i></button><?php endif; ?></div>
      <?php foreach ($banks as $b): ?><div class="rounded-xl border border-line p-3 mb-2 text-sm"><div class="flex items-center gap-2"><b><?= e(strtoupper($b['method'])) ?></b><?= $b['is_default'] ? pill('active', 'Default') : '' ?>
        <?php if (can('property.parties.manage')): ?><button class="ml-auto text-muted hover:text-rose-600" data-action="<?= e(url('api.php', ['r' => 'property/delete-bank', 'id' => $b['id']])) ?>" data-confirm="Remove this payout account?"><i class="fa-regular fa-trash-can text-xs"></i></button><?php endif; ?></div>
        <p class="text-muted"><?= e(trim($b['bank_name'] . ' ' . $b['branch'])) ?></p><p class="font-mono"><?= e($b['account_name']) ?> · <?= e($b['account_number']) ?></p></div><?php endforeach; ?>
      <?php if (!$banks) echo '<p class="text-sm text-muted">Where owner remittances are paid.</p>'; ?>
    </div>
  </div>
<?php echo ui_modal('m-bank', 'Payout account', '<input type="hidden" name="party_id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Method', ui_select('method', ui_kv_options(['bank' => 'Bank', 'mpesa' => 'M-Pesa', 'paybill' => 'Paybill', 'cheque' => 'Cheque'])))
  . ui_field('Bank / provider', ui_input('bank_name')) . ui_field('Branch', ui_input('branch')) . ui_field('Account name', ui_input('account_name', $p['display_name'])) . ui_field('Account / phone number', ui_input('account_number')) . '<div class="pt-6"><label class="vk-switch"><input type="checkbox" name="is_default" value="1" checked>Default</label></div></div>',
  url('api.php', ['r' => 'property/save-bank']), 'Save', 'max-w-xl', 'fa-building-columns');

elseif ($tab === 'units'): ?>
  <?php if ($owned): $ou = all("SELECT u.id, u.unit_no, u.status, pr.name AS property, b.code AS block_code FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id LEFT JOIN prpt_blocks b ON b.id = u.block_id WHERE u.id IN (" . in_ints(array_keys($owned)) . ") ORDER BY pr.name, u.unit_no"); ?>
  <div class="vk-panel overflow-hidden mb-6"><div class="px-5 py-3.5 border-b border-line font-bold"><i class="fa-solid fa-key text-accent mr-2"></i>Owned units</div>
    <table class="vk-table"><thead><tr><th>Unit</th><th>Property</th><th>Status</th><th class="num">Share</th></tr></thead><tbody>
      <?php foreach ($ou as $u): ?><tr data-href="<?= e(page('property/unit', ['id' => $u['id']])) ?>"><td><b><?= e($u['unit_no']) ?></b></td><td><?= e($u['property']) ?><?= $u['block_code'] ? ' · Block ' . e($u['block_code']) : '' ?></td><td><?= pill($u['status']) ?></td><td class="num"><?= num($owned[$u['id']], 2) ?>%</td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
  <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold"><i class="fa-solid fa-file-contract text-accent mr-2"></i>Billing accounts</div>
    <table class="vk-table"><thead><tr><th>Account</th><th>Unit</th><th>Role</th><th>Occupancy</th><th>Period</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($leases as $l): ?><tr data-href="<?= e(page('billing/lease', ['id' => $l['id']])) ?>"><td><b><?= e($l['lease_no']) ?></b></td><td><?= e($l['unit_no']) ?> · <?= e($l['property']) ?></td><td><?= e($l['role']) ?></td><td class="text-xs"><?= e(str_replace('_', ' ', $l['occupancy_type'])) ?></td><td class="text-xs"><?= fdate($l['start_date']) ?> → <?= $l['end_date'] ? fdate($l['end_date']) : 'open' ?></td><td><?= pill($l['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?= $leases ? '' : ui_empty('fa-file-contract', 'No accounts') ?></div>
<?php elseif ($tab === 'billing'):
    $inv = all("SELECT i.*, u.unit_no FROM bln_invoices i LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE i.party_id = ? ORDER BY i.issue_date DESC, i.id DESC LIMIT 100", [$id]);
    $pays = all("SELECT * FROM fin_payments WHERE party_id = ? ORDER BY paid_at DESC LIMIT 100", [$id]);
    $credits = all("SELECT c.*, u.unit_no, bt.name AS bt FROM fin_credits c LEFT JOIN prpt_units u ON u.id = c.unit_id LEFT JOIN cfg_bill_types bt ON bt.id = c.bill_type_id WHERE c.party_id = ? ORDER BY c.created_at DESC", [$id]); ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Invoices</div><table class="vk-table"><thead><tr><th>Invoice</th><th>Unit</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th></tr></thead><tbody>
      <?php foreach ($inv as $i): ?><tr data-href="<?= e(page('billing/invoice', ['id' => $i['id']])) ?>"><td><b><?= e($i['invoice_no']) ?></b><span class="block text-xs text-muted"><?= fdate($i['issue_date']) ?> · due <?= fdate($i['due_date']) ?></span></td><td><?= e($i['unit_no'] ?? '—') ?></td><td><?= pill($i['status']) ?></td><td class="num"><?= money($i['total'], false) ?></td><td class="num"><?= ui_money_cell($i['balance'], true) ?></td></tr><?php endforeach; ?>
    </tbody></table><?= $inv ? '' : ui_empty('fa-file-invoice', 'No invoices') ?></div>
    <div class="space-y-6">
      <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Payments</div><table class="vk-table"><thead><tr><th>Receipt</th><th>Ref</th><th>Status</th><th class="num">Amount</th></tr></thead><tbody>
        <?php foreach ($pays as $pm): ?><tr data-href="<?= e(page('finance/consume', ['payment' => $pm['uuid']])) ?>"><td><b><?= e($pm['receipt_no']) ?></b><span class="block text-xs text-muted"><?= fdatetime($pm['paid_at']) ?></span></td><td class="text-xs"><?= e($pm['reference'] ?: $pm['method']) ?></td><td><?= pill($pm['status']) ?></td><td class="num"><?= money($pm['amount'], false) ?></td></tr><?php endforeach; ?>
      </tbody></table><?= $pays ? '' : ui_empty('fa-money-bill', 'No payments') ?></div>
      <?php if ($credits): ?><div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Credits (overpayments / prepayments)</div><table class="vk-table"><thead><tr><th>Created</th><th>Earmarked for</th><th>Status</th><th class="num">Amount</th><th class="num">Remaining</th></tr></thead><tbody>
        <?php foreach ($credits as $c): ?><tr><td><?= fdate($c['created_at']) ?></td><td class="text-sm"><?= e(trim(($c['unit_no'] ? 'Unit ' . $c['unit_no'] : 'Any unit') . ' · ' . ($c['bt'] ?: 'any bill'))) ?></td><td><?= pill($c['status']) ?></td><td class="num"><?= money($c['amount'], false) ?></td><td class="num font-bold"><?= money($c['remaining'], false) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
<?php elseif ($tab === 'portal'): ?>
  <div class="vk-panel p-6 max-w-2xl">
    <div class="flex items-center gap-4">
      <span class="grid h-14 w-14 place-items-center rounded-2xl bg-accent/10 text-accent text-2xl"><i class="fa-solid fa-mobile-screen"></i></span>
      <div><p class="font-bold text-lg">Self-service portal</p><p class="text-sm text-muted">Status: <?= pill($p['portal_status'] ?? 'none', match ($p['portal_status']) { 'active' => 'Active', 'pending' => $p['activation_sent_at'] ? 'Invitation sent' : 'Not yet invited', 'disabled' => 'Disabled', default => 'No account' }) ?></p></div>
    </div>
    <dl class="mt-5 space-y-2 text-sm">
      <?php foreach ([['Username', $p['username'] ?: '— (chosen at activation)'], ['Invitation sent', fdatetime($p['activation_sent_at'])], ['Activated', fdatetime($p['activated_at'])], ['Last login', fdatetime($p['last_login_at'])], ['Installed as app', $p['app_installed_at'] ? fdatetime($p['app_installed_at']) : 'Not yet']] as [$k, $v]): ?>
        <div class="flex justify-between border-b border-line/60 pb-1.5"><dt class="text-muted"><?= $k ?></dt><dd class="font-semibold"><?= e($v) ?></dd></div>
      <?php endforeach; ?>
    </dl>
    <?php if (can('property.portal.invite')): ?>
    <div class="mt-6 flex flex-wrap gap-2">
      <?php if ($p['portal_status'] !== 'active'): ?>
        <button class="vk-btn-accent" data-action="<?= e(url('api.php', ['r' => 'property/portal-invite'])) ?>" data-payload='{"party_id":<?= $id ?>}' data-confirm="Email an activation link to <?= e($p['email'] ?: 'this person') ?>?" data-icon="question"><i class="fa-solid fa-paper-plane"></i><?= $p['activation_sent_at'] ? 'Resend' : 'Send' ?> activation link</button>
      <?php endif; ?>
      <?php if ($p['portal_status'] === 'active'): ?><button class="vk-btn-ghost !text-rose-600" data-action="<?= e(url('api.php', ['r' => 'property/portal-status'])) ?>" data-payload='{"party_id":<?= $id ?>,"status":"disabled"}' data-confirm="Disable portal access?"><i class="fa-solid fa-ban"></i>Disable access</button>
      <?php elseif ($p['portal_status'] === 'disabled'): ?><button class="vk-btn-ghost" data-action="<?= e(url('api.php', ['r' => 'property/portal-status'])) ?>" data-payload='{"party_id":<?= $id ?>,"status":"active"}'><i class="fa-solid fa-unlock"></i>Re-enable</button><?php endif; ?>
    </div>
    <?php if (!$p['email']): ?><p class="mt-3 text-xs text-rose-600">Add an email address to send an activation link.</p><?php endif; ?>
    <?php endif; ?>
  </div>
<?php elseif ($tab === 'documents'): ?>
  <div class="vk-panel overflow-hidden"><div class="flex items-center px-5 py-3.5 border-b border-line"><h3 class="font-bold">Documents shared with <?= e($p['first_name'] ?: $p['display_name']) ?></h3><?php if (can('property.documents.manage')): ?><button class="ml-auto vk-btn-accent !py-2 text-sm" data-modal-open="#m-doc"><i class="fa-solid fa-upload"></i>Upload</button><?php endif; ?></div>
    <?= documents_table("(d.owner_type = 'party' AND d.owner_id = :id) OR d.party_id = :id OR (d.owner_type = 'lease' AND d.owner_id IN (SELECT lease_id FROM bln_lease_parties WHERE party_id = :id))", ['id' => $id]) ?></div>
  <?= document_modal_html('party', $id, $id) ?>
<?php elseif ($tab === 'activity'):
    $tk = all("SELECT t.*, c.name AS cat FROM crm_tickets t JOIN crm_categories c ON c.id = t.category_id WHERE t.party_id = ? ORDER BY t.created_at DESC LIMIT 50", [$id]);
    $msgs = all("SELECT * FROM com_messages WHERE party_id = ? ORDER BY created_at DESC LIMIT 50", [$id]); ?>
  <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Tickets</div><table class="vk-table"><tbody>
      <?php foreach ($tk as $t): ?><tr data-href="<?= e(page('crm/ticket', ['id' => $t['id']])) ?>"><td><b><?= e($t['subject']) ?></b><span class="block text-xs text-muted"><?= e($t['ticket_no']) ?> · <?= e($t['cat']) ?> · <?= time_ago($t['created_at']) ?></span></td><td><?= pill($t['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?= $tk ? '' : ui_empty('fa-ticket', 'No tickets') ?></div>
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Messages sent</div><table class="vk-table"><tbody>
      <?php foreach ($msgs as $m): ?><tr><td><i class="fa-solid <?= $m['channel'] === 'sms' ? 'fa-comment-sms' : ($m['channel'] === 'email' ? 'fa-envelope' : 'fa-mobile-screen') ?> text-muted mr-1"></i><b class="text-sm"><?= e($m['subject'] ?: excerpt(strip_tags($m['body']), 60)) ?></b><span class="block text-xs text-muted"><?= e($m['to_address']) ?> · <?= time_ago($m['created_at']) ?></span></td><td><?= pill($m['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table><?= $msgs ? '' : ui_empty('fa-envelope', 'No messages') ?></div>
  </div>
<?php endif;
if (can('property.parties.manage')) echo party_modal_html();
