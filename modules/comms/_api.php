<?php
/** Communication module AJAX actions */
function _audience_from_post(): array {
    $a = ['type' => (string)input('audience', 'all'), 'property_ids' => array_map('intval', input_array('property_ids')), 'min_arrears' => (float)input_num('min_arrears', 1), 'days' => (int)input_int('days', 60), 'party_ids' => array_map('intval', input_array('party_ids'))];
    if (input_int('party_id')) { $a['type'] = 'custom'; $a['party_ids'] = [(int)input_int('party_id')]; }
    return $a;
}
return [
    'audience-preview' => ['comms.send', function () {
        $a = _audience_from_post(); $ps = $a['type'] === 'custom' && !$a['party_ids'] ? [] : audience_parties($a);
        $sample = $ps[0] ?? null; $html = null; $sms = null;
        if ($sample) { $v = party_unit_vars((int)$sample['id']) + party_vars($sample); $html = render_placeholders((string)input('body', ''), $v); $sms = render_placeholders(strip_tags((string)input('body', '')), $v); }
        json_ok(['count' => count($ps), 'with_email' => count(array_filter($ps, fn($p) => $p['email'])), 'with_phone' => count(array_filter($ps, fn($p) => $p['phone'])),
            'names' => array_map(fn($p) => $p['display_name'], array_slice($ps, 0, 12)), 'sample_name' => $sample['display_name'] ?? null, 'subject' => $sample ? render_placeholders((string)input('subject', ''), party_unit_vars((int)$sample['id']) + party_vars($sample)) : null, 'html' => $html, 'sms_len' => $sms ? mb_strlen($sms) : 0]);
    }],
    'template' => ['comms.send', function () { $t = one("SELECT * FROM com_templates WHERE id = ? OR code = ?", [input_int('id') ?? 0, (string)input('code', '')]); json_ok(['t' => $t]); }],
    'save-campaign' => ['comms.send', function () {
        $d = validate($_POST, ['channel' => 'required|in:email,sms,both,portal', 'body' => 'required']);
        $a = _audience_from_post();
        if (in_array($d['channel'], ['email', 'both', 'portal'], true) && !trim((string)input('subject'))) throw new ValidationError(['subject' => 'Add a subject.']);
        $name = trim((string)input('name')) ?: (mb_substr((string)input('subject') ?: strip_tags((string)$d['body']), 0, 60));
        $when = input('scheduled_at') ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)input('scheduled_at')))) : null;
        $id = insert('com_campaigns', ['name' => $name, 'channel' => $d['channel'], 'template_id' => input_int('template_id'), 'subject' => nullable(input('subject')), 'body' => in_array($d['channel'], ['sms'], true) ? strip_tags((string)$d['body']) : sanitize_html((string)$d['body']),
            'audience' => json_encode($a), 'status' => $when && $when > date('Y-m-d H:i:s') ? 'scheduled' : 'draft', 'scheduled_at' => $when, 'created_by' => staff_id()]);
        audit('comms', 'create', 'campaign', $id, 'Campaign ' . $name);
        if (!$when || $when <= date('Y-m-d H:i:s')) { $r = campaign_send($id); json_ok(['redirect' => page('comms/campaign', ['id' => $id])], "Sent to {$r['recipients']} recipient(s) — {$r['sent']} message(s)" . ($r['failed'] ? ", {$r['failed']} without a contact" : '') . '.'); }
        json_ok(['redirect' => page('comms/campaigns')], 'Scheduled for ' . fdatetime($when) . '.');
    }],
    'send-campaign' => ['comms.send', function () { $r = campaign_send((int)input_int('id')); json_ok([], "Sent to {$r['recipients']} recipient(s)."); }],
    'cancel-campaign' => ['comms.send', function () { q("UPDATE com_campaigns SET status = 'cancelled' WHERE id = ? AND status IN ('draft','scheduled')", [input_int('id')]); json_ok([], 'Cancelled.'); }],
    'run-reminders' => ['comms.templates.manage', function () { $n = run_reminders(); json_ok([], is_array($n) ? json_encode($n) : "$n reminder(s) sent."); }],
    'retry-message' => ['comms.send', function () {
        $m = one("SELECT * FROM com_messages WHERE id = ?", [input_int('id')]); if (!$m) json_fail('Not found', 404);
        send_message($m['channel'], $m['to_address'], $m['subject'], (string)$m['body'], ['party_id' => $m['party_id'], 'related_type' => $m['related_type'], 'related_id' => $m['related_id'], 'campaign_id' => $m['campaign_id']]);
        json_ok([], 'Re-sent.');
    }],
    'message' => ['comms.log.view', function () { $m = one("SELECT * FROM com_messages WHERE id = ?", [input_int('id')]); json_ok(['m' => $m]); }],
    'send-statement' => ['comms.send', function () {
        $type = (string)input('type'); $id = (int)input_int('id'); $partyId = $id;
        $st = statement_render($type, $id, ['from' => input_date('from', date('Y-01-01')), 'to' => input_date('to', date('Y-m-d')), 'bill_type_id' => input_int('bill_type_id'), 'unit_id' => input_int('unit_id')]);
        $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$partyId]); if (!$party) json_fail('Person not found.');
        $docId = insert('prpt_documents', ['uuid' => uuid4(), 'title' => $st['title'] . ' (' . date('j M Y') . ')', 'doc_type' => 'statement', 'owner_type' => 'party', 'owner_id' => $partyId, 'party_id' => $partyId, 'body_html' => $st['html'], 'visible_in_portal' => 1, 'created_by' => staff_id()]);
        $link = rtrim((string)config('app.portal_url'), '/') . '/?p=documents';
        if ($party['email']) send_message('email', $party['email'], $st['title'], email_wrap($st['title'], '<p>Dear ' . e($party['first_name'] ?: $party['display_name']) . ',</p><p>Please find your statement below. It is also saved in your <a href="' . e($link) . '">self-service portal</a>.</p><div style="font-size:12px">' . $st['html'] . '</div>'), ['party_id' => $partyId, 'related_type' => 'document', 'related_id' => $docId]);
        portal_notify($partyId, 'statement', 'New statement available', $st['title'], 'fa-file-lines', portal_page('documents'));
        audit('comms', 'send', 'statement', $docId, 'Sent ' . $st['title']);
        json_ok([], 'Statement sent' . ($party['email'] ? ' to ' . $party['email'] : '') . ' and shared in the portal.');
    }],
];
