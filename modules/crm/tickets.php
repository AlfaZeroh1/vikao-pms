<?php
$title = 'Tickets';
$subtitle = 'Requests and complaints from the self-service portal, phone, email and walk-ins — with SLA tracking';
$view = $_GET['view'] ?? 'list';
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'status' => (string)($_GET['status'] ?? 'active'), 'category_id' => (int)($_GET['category_id'] ?? 0), 'property_id' => (int)($_GET['property_id'] ?? 0), 'assigned' => (string)($_GET['assigned'] ?? ''), 'priority' => (string)($_GET['priority'] ?? '')];
$w = [scope_sql('t.property_id')]; $p = [];
if ($f['q'] !== '') { $w[] = '(t.ticket_no LIKE :q OR t.subject LIKE :q OR pt.display_name LIKE :q OR u.unit_no LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
if ($f['status'] === 'active') $w[] = "t.status NOT IN ('resolved','closed','cancelled')"; elseif ($f['status'] === 'breached') $w[] = "t.status NOT IN ('resolved','closed','cancelled') AND t.sla_due_at < NOW()"; elseif ($f['status']) { $w[] = 't.status = :st'; $p['st'] = $f['status']; }
if ($f['category_id']) { $w[] = 't.category_id = :c'; $p['c'] = $f['category_id']; }
if ($f['property_id']) { $w[] = 't.property_id = :pid'; $p['pid'] = $f['property_id']; }
if ($f['assigned'] === 'me') { $w[] = 't.assigned_to = :me'; $p['me'] = staff_id(); } elseif ($f['assigned'] === 'none') $w[] = 't.assigned_to IS NULL';
if ($f['priority']) { $w[] = 't.priority = :pr'; $p['pr'] = $f['priority']; }
if ($view === 'board' && $f['status'] === 'active') $w = array_values(array_filter($w, fn($x) => !str_contains($x, "NOT IN ('resolved'"))) + [99 => "(t.status NOT IN ('closed','cancelled') OR t.updated_at > DATE_SUB(NOW(), INTERVAL 7 DAY))"];
$sql = "FROM crm_tickets t JOIN crm_categories c ON c.id = t.category_id JOIN prpt_properties pr ON pr.id = t.property_id LEFT JOIN prpt_units u ON u.id = t.unit_id LEFT JOIN prpt_parties pt ON pt.id = t.party_id LEFT JOIN sys_users su ON su.id = t.assigned_to WHERE " . implode(' AND ', $w);
$rows = all("SELECT t.*, c.name AS cat, c.icon, pr.name AS property, u.unit_no, pt.display_name, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS agent, (SELECT COUNT(*) FROM crm_ticket_updates x WHERE x.ticket_id = t.id) AS updates $sql ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.sla_due_at LIMIT 300", $p);
$k = one("SELECT SUM(status NOT IN ('resolved','closed','cancelled')) open, SUM(status NOT IN ('resolved','closed','cancelled') AND sla_due_at < NOW()) breached, SUM(status NOT IN ('resolved','closed','cancelled') AND assigned_to IS NULL) unassigned, AVG(CASE WHEN resolved_at IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) avg_h, AVG(CASE WHEN rating IS NOT NULL THEN rating END) rating FROM crm_tickets t WHERE " . scope_sql('t.property_id'));
$actions = '<a class="vk-btn-ghost" href="' . e(page('crm/tickets', ['view' => $view === 'board' ? 'list' : 'board'] + array_diff_key($_GET, ['r' => 1, 'view' => 1]))) . '"><i class="fa-solid ' . ($view === 'board' ? 'fa-list' : 'fa-table-columns') . '"></i>' . ($view === 'board' ? 'List' : 'Board') . '</a>' . (can('crm.tickets.manage') ? '<button class="vk-btn-accent" data-modal-open="#m-ticket"><i class="fa-solid fa-plus"></i>New ticket</button>' : '');
$prioTone = ['urgent' => 'red', 'high' => 'amber', 'medium' => 'blue', 'low' => 'slate'];
?>
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-5">
  <?= ui_stat('fa-ticket', 'Open', num($k['open']), 'accent', null, page('crm/tickets')) ?><?= ui_stat('fa-fire', 'SLA breached', num($k['breached']), 'red', null, page('crm/tickets', ['status' => 'breached'])) ?>
  <?= ui_stat('fa-user-slash', 'Unassigned', num($k['unassigned']), 'violet', null, page('crm/tickets', ['assigned' => 'none'])) ?><?= ui_stat('fa-stopwatch', 'Avg. resolution (30 d)', $k['avg_h'] !== null ? num($k['avg_h'], 0) . ' h' : '—', 'blue') ?><?= ui_stat('fa-star', 'Satisfaction', $k['rating'] ? num($k['rating'], 1) . ' / 5' : '—', 'green') ?>
</div>
<?= ui_filter_open() ?>
  <input type="hidden" name="view" value="<?= e($view) ?>">
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Ticket, subject, name, unit"'), 'w-56') ?>
  <?= ui_field('Status', ui_select('status', ui_kv_options(['active' => 'Active', 'breached' => 'SLA breached'] + TICKET_STATUSES, $f['status'], 'All')), 'w-40') ?>
  <?= ui_field('Category', ui_select('category_id', ui_options(all("SELECT id, name FROM crm_categories ORDER BY sort_order"), 'id', 'name', $f['category_id'], 'Any')), 'w-40') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'Any')), 'w-44') ?>
  <?= ui_field('Assigned', ui_select('assigned', ui_kv_options(['me' => 'To me', 'none' => 'Unassigned'], $f['assigned'], 'Anyone')), 'w-36') ?>
  <?= ui_field('Priority', ui_select('priority', ui_kv_options(['urgent' => 'Urgent', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'], $f['priority'], 'Any')), 'w-32') ?>
<?= ui_filter_close() ?>
<?php if ($view === 'board'): $cols = ['open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'awaiting_customer' => 'Waiting on customer', 'on_hold' => 'On hold', 'resolved' => 'Resolved']; $by = []; foreach ($rows as $t) $by[$t['status']][] = $t; ?>
<div class="flex gap-4 overflow-x-auto pb-4" id="board">
  <?php foreach ($cols as $s => $l): ?><div class="w-72 shrink-0"><p class="text-sm font-bold mb-2 px-1"><?= $l ?> <span class="text-muted font-normal"><?= count($by[$s] ?? []) ?></span></p>
    <div class="col min-h-[300px] space-y-2 rounded-2xl bg-surface2/50 p-2" data-status="<?= $s ?>"><?php foreach ($by[$s] ?? [] as $t): $late = $t['sla_due_at'] < date('Y-m-d H:i:s') && !in_array($t['status'], ['resolved', 'closed'], true); ?>
      <a href="<?= e(page('crm/ticket', ['id' => $t['id']])) ?>" draggable="true" data-id="<?= $t['id'] ?>" class="card block vk-panel p-3 hover:border-accent/60 <?= $late ? '!border-rose-400' : '' ?>">
        <div class="flex gap-2 items-start"><i class="fa-solid <?= e($t['icon'] ?: 'fa-ticket') ?> text-accent mt-0.5"></i><b class="text-sm flex-1"><?= e($t['subject']) ?></b><?= pill($t['priority'], ucfirst($t['priority']), $prioTone[$t['priority']]) ?></div>
        <p class="text-xs text-muted mt-1"><?= e($t['ticket_no']) ?> · <?= e($t['unit_no'] ?: $t['property']) ?><?= $t['display_name'] ? ' · ' . e($t['display_name']) : '' ?></p>
        <p class="text-[11px] mt-1 <?= $late ? 'text-rose-600 font-bold' : 'text-muted' ?>"><i class="fa-regular fa-clock"></i> <?= $late ? 'SLA breached ' : 'due ' ?><?= time_ago($t['sla_due_at']) ?> · <?= e($t['agent'] ?: 'unassigned') ?></p></a>
    <?php endforeach; ?></div></div><?php endforeach; ?>
</div>
<?php page_script('<script>let dg=null;$("#board").on("dragstart",".card",function(){dg=this}).on("dragover",".col",e=>e.preventDefault()).on("drop",".col",function(e){e.preventDefault();if(!dg)return;$(this).append(dg);VK.api("crm/move",{id:$(dg).data("id"),status:$(this).data("status")}).then(r=>VK.toast(r.message));dg=null})</script>');
else: ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Ticket</th><th>From</th><th>Category</th><th>Priority</th><th>Status</th><th>Assigned</th><th>SLA</th></tr></thead><tbody>
  <?php foreach ($rows as $t): $late = $t['sla_due_at'] < date('Y-m-d H:i:s') && !in_array($t['status'], ['resolved', 'closed', 'cancelled'], true); ?><tr data-href="<?= e(page('crm/ticket', ['id' => $t['id']])) ?>">
    <td><b><?= e($t['subject']) ?></b><span class="block text-xs text-muted"><?= e($t['ticket_no']) ?> · <?= e($t['source']) ?> · <?= time_ago($t['created_at']) ?> · <?= (int)$t['updates'] ?> update(s)</span></td>
    <td class="text-sm"><?= e($t['display_name'] ?: '—') ?><span class="block text-xs text-muted"><?= e(($t['unit_no'] ? $t['unit_no'] . ' · ' : '') . $t['property']) ?></span></td>
    <td class="text-sm"><i class="fa-solid <?= e($t['icon'] ?: 'fa-ticket') ?> text-muted mr-1"></i><?= e($t['cat']) ?></td><td><?= pill($t['priority'], ucfirst($t['priority']), $prioTone[$t['priority']]) ?></td><td><?= pill($t['status'], TICKET_STATUSES[$t['status']]) ?></td>
    <td class="text-sm"><?= e($t['agent'] ?: '—') ?></td><td class="text-xs <?= $late ? 'text-rose-600 font-bold' : 'text-muted' ?>"><?= $late ? '<i class="fa-solid fa-fire"></i> ' : '' ?><?= fdatetime($t['sla_due_at']) ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="7">' . ui_empty('fa-ticket', 'No tickets match') . '</td></tr>'; ?>
  </tbody></table></div></div>
<?php endif;
if (can('crm.tickets.manage')) echo ui_modal('m-ticket', 'New ticket', '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Customer', ui_party_picker('party_id')) . ui_field('Unit', ui_unit_picker('unit_id'))
  . ui_field('Or property (common area)', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', null, '—'))) . ui_field('Category *', ui_select('category_id', ui_options(all("SELECT id, name FROM crm_categories WHERE is_active = 1 ORDER BY sort_order"), 'id', 'name'), 'required'))
  . ui_field('Subject *', ui_input('subject', '', 'text', 'required'), 'sm:col-span-2') . '<div class="sm:col-span-2">' . ui_field('Details', '<textarea name="description" class="vk-textarea" rows="3"></textarea>') . '</div>'
  . ui_field('Priority', ui_select('priority', ui_kv_options(['medium' => 'Medium', 'low' => 'Low', 'high' => 'High', 'urgent' => 'Urgent']))) . ui_field('Source', ui_select('source', ui_kv_options(['phone' => 'Phone', 'walk_in' => 'Walk-in', 'email' => 'Email', 'whatsapp' => 'WhatsApp', 'staff' => 'Staff'])))
  . ui_field('Assign to', ui_select('assigned_to', ui_options(staff_for_select(), 'id', 'name', null, 'Category default'))) . ui_field('Attachment', '<input type="file" name="attachment" class="vk-input">') . '</div>', url('api.php', ['r' => 'crm/create-ticket']), 'Create ticket', 'max-w-2xl', 'fa-ticket');
