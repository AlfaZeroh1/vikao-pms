<?php
/** CRM AJAX actions */
return [
    'create-ticket' => ['crm.tickets.manage', function () {
        $d = validate($_POST, ['category_id' => 'required', 'subject' => 'required|min:3']);
        $t = ticket_create($_POST + ['source' => input('source', 'staff'), 'attachment_path' => save_upload('attachment', 'tickets')], 'staff', staff_id());
        json_ok(['redirect' => page('crm/ticket', ['id' => $t['id']])], 'Ticket ' . $t['ticket_no'] . ' created.');
    }],
    'reply' => ['crm.tickets.manage', function () {
        $att = save_upload('attachment', 'tickets');
        ticket_add_update((int)input_int('ticket_id'), trim((string)input('body', '')), (bool)input('is_internal'), input('status') ?: null, 'staff', staff_id(), $att);
        json_ok([], input('is_internal') ? 'Internal note added.' : 'Update sent to the customer.');
    }],
    'assign' => ['crm.tickets.manage', function () {
        $t = one("SELECT * FROM crm_tickets WHERE id = ?", [input_int('id')]); $u = input_int('assigned_to'); $v = input_int('vendor_id');
        $row = ['assigned_to' => $u, 'vendor_id' => $v]; if ($u && $t['status'] === 'open') $row['status'] = 'assigned';
        update('crm_tickets', $row, 'id = :id', ['id' => $t['id']]);
        $note = [];
        if ($u && (int)$u !== (int)$t['assigned_to']) { $n = staff_name(one("SELECT * FROM sys_users WHERE id = ?", [$u])); $note[] = 'Assigned to ' . $n; staff_notify($u, 'ticket', 'Ticket ' . $t['ticket_no'] . ' assigned to you', $t['subject'], 'fa-ticket', page('crm/ticket', ['id' => $t['id']])); }
        if ($v && (int)$v !== (int)$t['vendor_id']) { $vd = one("SELECT * FROM fin_vendors WHERE id = ?", [$v]); $note[] = 'Vendor: ' . $vd['name'];
            if (input('notify_vendor') && $vd['email']) send_message('email', $vd['email'], 'Job ' . $t['ticket_no'] . ': ' . $t['subject'], email_wrap('New job', '<p>' . nl2br(e($t['description'])) . '</p><p>Unit: ' . e((string)val("SELECT unit_no FROM prpt_units WHERE id = ?", [$t['unit_id']])) . ' · ' . e((string)val("SELECT name FROM prpt_properties WHERE id = ?", [$t['property_id']])) . '</p>'), ['related_type' => 'ticket', 'related_id' => $t['id']]);
            if (input('notify_vendor') && $vd['phone']) send_message('sms', $vd['phone'], null, 'Vikao job ' . $t['ticket_no'] . ': ' . excerpt($t['subject'], 80) . '. Unit ' . val("SELECT unit_no FROM prpt_units WHERE id = ?", [$t['unit_id']]), ['related_type' => 'ticket', 'related_id' => $t['id']]); }
        if ($note) ticket_add_update((int)$t['id'], implode(' · ', $note), true);
        json_ok([], 'Saved.');
    }],
    'update-ticket' => ['crm.tickets.manage', function () {
        $t = one("SELECT * FROM crm_tickets WHERE id = ?", [input_int('id')]);
        $row = ['priority' => input('priority', $t['priority']), 'category_id' => input_int('category_id') ?? $t['category_id'], 'cost_estimate' => input_num('cost_estimate'), 'cost_bearer' => input('cost_bearer') ?: null, 'sla_due_at' => input('sla_due_at') ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)input('sla_due_at')))) : $t['sla_due_at']];
        update('crm_tickets', $row, 'id = :id', ['id' => $t['id']]); audit('crm', 'update', 'ticket', $t['id'], 'Updated ' . $t['ticket_no'], $t, $row);
        json_ok([], 'Ticket updated.');
    }],
    'bulk-status' => ['crm.tickets.manage', function () { foreach (array_map('intval', input_array('ids')) as $id) ticket_add_update($id, '', false, (string)input('status')); json_ok([], 'Updated.'); }],
    'move' => ['crm.tickets.manage', function () { ticket_add_update((int)input_int('id'), '', false, (string)input('status')); json_ok([], 'Moved.'); }],
    'save-inspection' => ['crm.maintenance.manage', function () {
        $d = validate($_POST, ['unit_id' => 'required', 'inspection_type' => 'required', 'inspected_on' => 'required|date']);
        $items = []; foreach ((array)($_POST['items'] ?? []) as $it) if (trim((string)($it['area'] ?? '')) !== '') $items[] = ['area' => $it['area'], 'condition' => $it['condition'] ?? 'good', 'note' => $it['note'] ?? ''];
        $l = unit_active_lease((int)$d['unit_id']);
        $id = insert('crm_inspections', ['unit_id' => (int)$d['unit_id'], 'lease_id' => $l['id'] ?? null, 'inspection_type' => $d['inspection_type'], 'inspected_on' => $d['inspected_on'], 'inspector_id' => staff_id(), 'checklist' => json_encode($items),
            'overall' => input('overall', 'good'), 'damages_cost' => input_num('damages_cost'), 'notes' => nullable(input('notes'))]);
        if (input('raise_tickets')) foreach ($items as $it) if (in_array($it['condition'], ['poor', 'damaged'], true)) {
            $cat = (int)val("SELECT id FROM crm_categories WHERE kind = 'maintenance' ORDER BY sort_order LIMIT 1");
            ticket_create(['category_id' => $cat, 'unit_id' => (int)$d['unit_id'], 'subject' => 'Inspection: ' . $it['area'] . ' needs attention', 'description' => $it['note'], 'priority' => 'medium', 'source' => 'inspection'], 'staff', staff_id());
        }
        audit('crm', 'create', 'inspection', $id, ucfirst(str_replace('_', ' ', $d['inspection_type'])) . ' inspection');
        json_ok([], 'Inspection saved.');
    }],
    'run-maintenance' => ['crm.maintenance.manage', function () { $n = crm_run_maintenance(); json_ok([], "$n maintenance ticket(s) raised."); }],
];
