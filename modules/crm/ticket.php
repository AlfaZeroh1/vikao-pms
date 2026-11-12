<?php
$t = one("SELECT t.*, c.name AS cat, c.icon, pr.name AS property, u.unit_no, pt.display_name, pt.phone, pt.email, v.name AS vendor, v.phone AS vendor_phone, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS agent
    FROM crm_tickets t JOIN crm_categories c ON c.id = t.category_id JOIN prpt_properties pr ON pr.id = t.property_id LEFT JOIN prpt_units u ON u.id = t.unit_id LEFT JOIN prpt_parties pt ON pt.id = t.party_id LEFT JOIN fin_vendors v ON v.id = t.vendor_id LEFT JOIN sys_users su ON su.id = t.assigned_to WHERE t.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$t) { echo ui_empty('fa-ticket', 'Ticket not found'); return; }
$id = (int)$t['id'];
$title = $t['subject']; $subtitle = e($t['ticket_no']) . ' · ' . e($t['cat']) . ' · ' . pill($t['status'], TICKET_STATUSES[$t['status']]);
$crumbs = [['Tickets', page('crm/tickets')]];
$ups = all("SELECT * FROM crm_ticket_updates WHERE ticket_id = ? ORDER BY created_at, id", [$id]);
$exp = all("SELECT e.*, c.name AS cat FROM fin_expenses e JOIN fin_expense_categories c ON c.id = e.category_id WHERE e.ticket_id = ? ORDER BY e.expense_date", [$id]);
$late = $t['sla_due_at'] < date('Y-m-d H:i:s') && !in_array($t['status'], ['resolved', 'closed', 'cancelled'], true);
$can = can('crm.tickets.manage');
$actions = $t['phone'] ? '<a class="vk-btn-ghost" href="tel:' . e($t['phone']) . '"><i class="fa-solid fa-phone"></i>Call</a>' : '';
if (can('finance.expenses.manage')) $actions .= '<a class="vk-btn-ghost" href="' . e(page('finance/expenses', ['ticket_id' => $id, 'new' => 1])) . '"><i class="fa-solid fa-receipt"></i>Add cost</a>';
?>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_340px] gap-6 items-start">
  <div class="space-y-4">
    <div class="vk-panel p-5"><div class="flex items-center gap-3 mb-2"><?= ui_avatar($t['display_name'] ?: 'Staff') ?><div><b><?= e($t['display_name'] ?: 'Raised by staff') ?></b><span class="block text-xs text-muted"><?= fdatetime($t['created_at']) ?> · via <?= e($t['source']) ?></span></div></div>
      <div class="text-sm whitespace-pre-line"><?= e($t['description'] ?: '(no details)') ?></div>
      <?php if ($t['preferred_time'] || $t['access_permission']): ?><p class="mt-3 text-xs text-muted"><i class="fa-solid fa-key"></i> Access: <?= e(str_replace('_', ' ', $t['access_permission'])) ?><?= $t['preferred_time'] ? ' · Preferred time: ' . e($t['preferred_time']) : '' ?></p><?php endif; ?></div>
    <?php foreach ($ups as $u): $mine = $u['author_type'] === 'staff'; ?>
      <div class="flex gap-3 <?= $mine ? '' : 'flex-row-reverse' ?>">
        <?= ui_avatar($u['author_name'] ?: '?', 'h-8 w-8') ?>
        <div class="max-w-[85%] rounded-2xl p-3.5 text-sm <?= $u['is_internal'] ? 'bg-amber-500/10 border border-amber-500/30' : ($mine ? 'vk-panel' : 'bg-accent/10') ?>">
          <p class="text-xs mb-1"><b><?= e($u['author_name']) ?></b> <span class="text-muted">· <?= fdatetime($u['created_at']) ?></span><?= $u['is_internal'] ? ' <span class="text-amber-700 font-bold"><i class="fa-solid fa-lock"></i> internal</span>' : '' ?></p>
          <?php if ($u['status_to']): ?><p class="text-xs mb-1"><?= pill($u['status_from'] ?: 'open', TICKET_STATUSES[$u['status_from']] ?? '') ?> → <?= pill($u['status_to'], TICKET_STATUSES[$u['status_to']] ?? '') ?></p><?php endif; ?>
          <div class="whitespace-pre-line"><?= e($u['body']) ?></div>
          <?php if ($u['attachment_path']): ?><a class="mt-2 inline-flex items-center gap-1 text-accent text-xs font-semibold" target="_blank" href="<?= e(file_url($u['attachment_path'])) ?>"><i class="fa-solid fa-paperclip"></i>Attachment</a><?php endif; ?>
        </div></div>
    <?php endforeach; ?>
    <?php if ($t['rating']): ?><div class="vk-panel p-4 text-sm"><span class="text-amber-500"><?= str_repeat('★', (int)$t['rating']) . str_repeat('☆', 5 - (int)$t['rating']) ?></span> Customer feedback<?= $t['rating_comment'] ? ': “' . e($t['rating_comment']) . '”' : '' ?></div><?php endif; ?>
    <?php if ($can): ?>
    <form data-ajax="<?= e(url('api.php', ['r' => 'crm/reply'])) ?>" class="vk-panel p-4 space-y-3">
      <input type="hidden" name="ticket_id" value="<?= $id ?>">
      <textarea name="body" rows="3" class="vk-textarea" placeholder="Reply to the customer, or add an internal note…"></textarea>
      <div class="flex flex-wrap items-center gap-3">
        <select name="status" class="vk-select !w-52 !py-2"><option value="">Keep status (<?= e(TICKET_STATUSES[$t['status']]) ?>)</option><?php foreach (TICKET_STATUSES as $k => $l) if ($k !== $t['status']) echo '<option value="' . $k . '">→ ' . e($l) . '</option>'; ?></select>
        <input type="file" name="attachment" class="text-xs">
        <label class="vk-switch text-sm"><input type="checkbox" name="is_internal" value="1">Internal note</label>
        <button class="ml-auto vk-btn-accent !py-2"><i class="fa-solid fa-paper-plane"></i>Send</button>
      </div>
      <p class="text-[11px] text-muted">Public replies are emailed / SMSed to the customer and appear in their portal.</p>
    </form>
    <?php endif; ?>
  </div>
  <aside class="space-y-4 xl:sticky xl:top-20">
    <div class="vk-panel p-5 space-y-2 text-sm">
      <div class="flex justify-between"><span class="text-muted">Unit</span><b><?= $t['unit_id'] ? '<a class="hover:text-accent" href="' . e(page('property/unit', ['id' => $t['unit_id']])) . '">' . e($t['unit_no']) . '</a>' : 'Common area' ?></b></div>
      <div class="flex justify-between"><span class="text-muted">Property</span><b><?= e($t['property']) ?></b></div>
      <?php if ($t['party_id']): ?><div class="flex justify-between"><span class="text-muted">Customer</span><a class="font-bold hover:text-accent" href="<?= e(page('property/party', ['id' => $t['party_id']])) ?>"><?= e($t['display_name']) ?></a></div><?php endif; ?>
      <div class="flex justify-between"><span class="text-muted">SLA due</span><b class="<?= $late ? 'text-rose-600' : '' ?>"><?= fdatetime($t['sla_due_at']) ?></b></div>
      <div class="flex justify-between"><span class="text-muted">First response</span><b><?= $t['first_response_at'] ? time_ago($t['first_response_at']) : '—' ?></b></div>
      <?php if ($t['resolved_at']): ?><div class="flex justify-between"><span class="text-muted">Resolved</span><b><?= fdatetime($t['resolved_at']) ?></b></div><?php endif; ?>
    </div>
    <?php if ($can): ?>
    <form data-ajax="<?= e(url('api.php', ['r' => 'crm/assign'])) ?>" class="vk-panel p-5 space-y-3"><input type="hidden" name="id" value="<?= $id ?>">
      <h3 class="font-bold text-sm">Assignment</h3>
      <?= ui_field('Staff', ui_select('assigned_to', ui_options(staff_for_select(), 'id', 'name', $t['assigned_to'], 'Unassigned'))) ?>
      <?= ui_field('Vendor / technician', ui_select('vendor_id', ui_options(all("SELECT id, CONCAT(name, IF(service IS NULL, '', CONCAT(' · ', service))) AS name FROM fin_vendors WHERE is_active = 1 ORDER BY name"), 'id', 'name', $t['vendor_id'], '—'))) ?>
      <label class="vk-switch text-sm"><input type="checkbox" name="notify_vendor" value="1" checked>Send the job to the vendor (email / SMS)</label>
      <button class="vk-btn-dark w-full justify-center !py-2">Save</button></form>
    <form data-ajax="<?= e(url('api.php', ['r' => 'crm/update-ticket'])) ?>" class="vk-panel p-5 space-y-3"><input type="hidden" name="id" value="<?= $id ?>">
      <h3 class="font-bold text-sm">Details</h3>
      <div class="grid grid-cols-2 gap-3"><?= ui_field('Priority', ui_select('priority', ui_kv_options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'], $t['priority']))) ?>
        <?= ui_field('Category', ui_select('category_id', ui_options(all("SELECT id, name FROM crm_categories ORDER BY sort_order"), 'id', 'name', $t['category_id']))) ?>
        <?= ui_field('Cost estimate', ui_input('cost_estimate', $t['cost_estimate'], 'number', 'step="any"')) ?><?= ui_field('Cost borne by', ui_select('cost_bearer', ui_kv_options(['owner' => 'Owner', 'tenant' => 'Tenant', 'agency' => 'Agency', 'property_fund' => 'Property fund'], $t['cost_bearer'], '—'))) ?>
        <div class="col-span-2"><?= ui_field('SLA due', ui_input('sla_due_at', $t['sla_due_at'], 'datetime-local')) ?></div></div>
      <button class="vk-btn-dark w-full justify-center !py-2">Update</button></form>
    <?php endif; ?>
    <div class="vk-panel p-5"><div class="flex items-center mb-2"><h3 class="font-bold text-sm">Costs</h3><b class="ml-auto text-sm"><?= money($t['cost_actual'] ?? 0) ?></b></div>
      <?php foreach ($exp as $x): ?><p class="text-xs flex justify-between"><span><?= e($x['description']) ?> <span class="text-muted">· <?= e($x['chargeable_to']) ?></span></span><b><?= money($x['amount'], false) ?></b></p><?php endforeach; if (!$exp) echo '<p class="text-xs text-muted">No costs recorded. Expenses linked to this ticket are deducted from the owner\'s remittance or recharged to the tenant.</p>'; ?></div>
  </aside>
</div>
