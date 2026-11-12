<?php
/** CRM engine: tickets from the portal or staff, SLA, threaded updates, notifications both ways. */

const TICKET_STATUSES = ['open' => 'Open', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'awaiting_customer' => 'Waiting on you', 'on_hold' => 'On hold', 'resolved' => 'Resolved', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];

/** $d: category_id, subject, description, party_id?, unit_id?, property_id?, priority, source, access_permission?, preferred_time?, attachment_path? */
function ticket_create(array $d, string $byType = 'staff', ?int $byId = null): array {
    $cat = one("SELECT * FROM crm_categories WHERE id = ?", [(int)$d['category_id']]);
    if (!$cat) throw new ValidationError(['category_id' => 'Choose a category.']);
    $unitId = nullable($d['unit_id'] ?? null);
    $propId = nullable($d['property_id'] ?? null) ?? ($unitId ? (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$unitId]) : null);
    if (!$propId) throw new ValidationError(['unit_id' => 'Choose the unit or property this is about.']);
    $prio = $d['priority'] ?? 'medium';
    $slaH = (int)$cat['sla_hours'] * ($prio === 'urgent' ? 0.25 : ($prio === 'high' ? 0.5 : ($prio === 'low' ? 2 : 1)));
    $assignee = nullable($d['assigned_to'] ?? null) ?? $cat['default_assignee'];
    $id = insert('crm_tickets', ['uuid' => uuid4(), 'ticket_no' => next_no('ticket'), 'category_id' => (int)$cat['id'], 'party_id' => nullable($d['party_id'] ?? null), 'property_id' => $propId, 'unit_id' => $unitId,
        'subject' => mb_substr(trim((string)$d['subject']), 0, 160), 'description' => (string)($d['description'] ?? ''), 'priority' => $prio, 'status' => $assignee ? 'assigned' : 'open', 'source' => $d['source'] ?? $byType,
        'assigned_to' => $assignee, 'access_permission' => $d['access_permission'] ?? 'with_notice', 'preferred_time' => nullable($d['preferred_time'] ?? null),
        'sla_due_at' => date('Y-m-d H:i:s', time() + (int)round($slaH * 3600)), 'created_by_type' => $byType, 'created_by_id' => $byId]);
    $t = one("SELECT * FROM crm_tickets WHERE id = ?", [$id]);
    if (!empty($d['attachment_path'])) insert('crm_ticket_updates', ['ticket_id' => $id, 'author_type' => $byType, 'author_id' => $byId, 'author_name' => $byType === 'portal' ? (string)val("SELECT display_name FROM prpt_parties WHERE id = ?", [$byId]) : staff_name(), 'body' => 'Attachment', 'is_internal' => 0, 'attachment_path' => $d['attachment_path']]);
    $link = page('crm/ticket', ['id' => $id]);
    if ($assignee) staff_notify((int)$assignee, 'ticket', 'Ticket ' . $t['ticket_no'] . ': ' . $t['subject'], ucfirst($prio) . ' · ' . $cat['name'], 'fa-ticket', $link);
    else staff_notify_perm('crm.tickets.manage', 'ticket', 'New ticket ' . $t['ticket_no'], $t['subject'], 'fa-ticket', $link);
    if ($t['party_id']) {
        $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$t['party_id']]);
        try { send_templated('ticket_received', $party, ['ticket_no' => $t['ticket_no'], 'subject' => $t['subject']], null, 'ticket', $id); } catch (Throwable $e) { error_log((string)$e); }
        portal_notify((int)$t['party_id'], 'ticket', 'Request ' . $t['ticket_no'] . ' received', $t['subject'], 'fa-ticket', portal_page('ticket', ['id' => $t['uuid']]));
    }
    audit('crm', 'create', 'ticket', $id, 'Ticket ' . $t['ticket_no'] . ': ' . $t['subject']);
    return $t;
}

/** Add an update; optionally change status. Public updates notify the customer (email/SMS + portal). */
function ticket_add_update(int $ticketId, string $body, bool $internal = false, ?string $statusTo = null, string $authorType = 'staff', ?int $authorId = null, ?string $attachment = null): void {
    $t = one("SELECT * FROM crm_tickets WHERE id = ?", [$ticketId]);
    if (!$t) throw new RuntimeException('Ticket not found.');
    $statusTo = $statusTo && $statusTo !== $t['status'] && isset(TICKET_STATUSES[$statusTo]) ? $statusTo : null;
    $name = $authorType === 'portal' ? (string)val("SELECT display_name FROM prpt_parties WHERE id = ?", [$authorId]) : ($authorType === 'system' ? 'System' : staff_name());
    if (trim($body) === '' && !$statusTo && !$attachment) throw new ValidationError(['body' => 'Write an update.']);
    insert('crm_ticket_updates', ['ticket_id' => $ticketId, 'author_type' => $authorType, 'author_id' => $authorId ?? ($authorType === 'staff' ? staff_id() : null), 'author_name' => $name,
        'body' => $body !== '' ? $body : ($statusTo ? 'Status changed to ' . TICKET_STATUSES[$statusTo] : ''), 'is_internal' => (int)$internal, 'status_from' => $statusTo ? $t['status'] : null, 'status_to' => $statusTo, 'attachment_path' => $attachment]);
    $row = ['updated_at' => date('Y-m-d H:i:s')];
    if ($statusTo) { $row['status'] = $statusTo; if ($statusTo === 'resolved') $row['resolved_at'] = date('Y-m-d H:i:s'); if ($statusTo === 'closed') $row['closed_at'] = date('Y-m-d H:i:s'); }
    if ($authorType === 'staff' && !$internal && !$t['first_response_at']) $row['first_response_at'] = date('Y-m-d H:i:s');
    if ($authorType === 'portal' && in_array($t['status'], ['awaiting_customer', 'resolved'], true) && !$statusTo) $row['status'] = 'in_progress';
    update('crm_tickets', $row, 'id = :id', ['id' => $ticketId]);
    if ($authorType === 'staff' && !$internal && $t['party_id']) {
        $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$t['party_id']]);
        try { send_templated('ticket_update', $party, ['ticket_no' => $t['ticket_no'], 'subject' => $t['subject'], 'status' => TICKET_STATUSES[$statusTo ?? $t['status']], 'message' => e($body)], null, 'ticket', $ticketId); } catch (Throwable $e) { error_log((string)$e); }
        portal_notify((int)$t['party_id'], 'ticket', 'Update on ' . $t['ticket_no'], excerpt($body ?: ('Status: ' . TICKET_STATUSES[$statusTo ?? $t['status']]), 140), 'fa-ticket', portal_page('ticket', ['id' => $t['uuid']]));
    }
    if ($authorType === 'portal') {
        $to = $t['assigned_to'] ? [(int)$t['assigned_to']] : [];
        foreach ($to as $u) staff_notify($u, 'ticket', 'Customer replied on ' . $t['ticket_no'], excerpt($body, 120), 'fa-reply', page('crm/ticket', ['id' => $ticketId]));
        if (!$to) staff_notify_perm('crm.tickets.manage', 'ticket', 'Customer replied on ' . $t['ticket_no'], excerpt($body, 120), 'fa-reply', page('crm/ticket', ['id' => $ticketId]));
    }
}

/** Preventive maintenance (cron): raise a ticket for each schedule that is due, then roll the date forward. */
function crm_run_maintenance(): int {
    $n = 0;
    foreach (all("SELECT * FROM crm_maintenance_schedules WHERE is_active = 1 AND next_due_date <= CURDATE()") as $s) {
        $t = ticket_create(['category_id' => (int)$s['category_id'], 'property_id' => (int)$s['property_id'], 'subject' => 'Scheduled: ' . $s['title'], 'description' => 'Preventive maintenance every ' . $s['every_days'] . ' days.' . ($s['estimated_cost'] ? ' Estimated cost ' . money($s['estimated_cost']) . '.' : ''), 'priority' => 'medium', 'source' => 'staff'], 'system');
        if ($s['vendor_id']) q("UPDATE crm_tickets SET vendor_id = ?, cost_estimate = ? WHERE id = ?", [$s['vendor_id'], $s['estimated_cost'], $t['id']]);
        q("UPDATE crm_maintenance_schedules SET next_due_date = DATE_ADD(next_due_date, INTERVAL every_days DAY) WHERE id = ?", [$s['id']]);
        $n++;
    }
    return $n;
}
