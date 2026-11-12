<?php
/** Communication engine: templates, placeholders, email/SMS/portal dispatch, campaigns, reminders. */

function email_wrap(string $title, string $inner): string {
    $co = e(setting('company_name', config('app.company')));
    $foot = e(setting('company_address', 'Nairobi, Kenya')) . ' · ' . e(setting('company_phone', '')) . ' · ' . e(setting('company_email', ''));
    return <<<HTML
<!doctype html><html><body style="margin:0;background:#FDFBF7;font-family:Segoe UI,Arial,sans-serif;color:#1E293B">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:16px;overflow:hidden;border:1px solid #eee7da">
<tr><td style="background:#1E293B;padding:18px 28px;color:#FDFBF7;font-size:20px;font-weight:700">vikao<span style="color:#F59E0B">.</span> <span style="font-size:12px;font-weight:500;opacity:.7">{$co}</span></td></tr>
<tr><td style="padding:28px;font-size:15px;line-height:1.6"><h2 style="margin:0 0 14px;font-size:19px">{$title}</h2>{$inner}</td></tr>
<tr><td style="padding:16px 28px;background:#F8F5EE;color:#64748B;font-size:12px">{$foot}</td></tr></table></td></tr></table></body></html>
HTML;
}

/** Variables available to every template for a party */
function party_vars(array $party): array {
    return ['name' => $party['display_name'], 'first_name' => $party['first_name'] ?: $party['display_name'], 'email' => $party['email'], 'phone' => $party['phone'],
        'party_no' => $party['party_no'], 'company_name' => setting('company_name', config('app.company')), 'portal_url' => rtrim((string)config('app.portal_url'), '/'),
        'paybill' => setting('paybill_number', ''), 'support_phone' => setting('company_phone', ''), 'today' => date('j M Y'),
        'balance' => party_balance((int)$party['id'])];
}
function party_balance(int $partyId): float {
    $owed = (float)val("SELECT COALESCE(SUM(balance),0) FROM bln_invoices WHERE party_id = ? AND status NOT IN ('void','written_off','draft')", [$partyId]);
    $credit = (float)val("SELECT COALESCE(SUM(remaining),0) FROM fin_credits WHERE party_id = ? AND status = 'open'", [$partyId]);
    $unalloc = (float)val("SELECT COALESCE(SUM(unallocated_amount),0) FROM fin_payments WHERE party_id = ? AND status IN ('unallocated','partially_allocated')", [$partyId]);
    return round2($owed - $credit - $unalloc);
}

/**
 * Low-level send. $o: party_id, user_id, campaign_id, related_type, related_id
 * Email goes through the SMTP integration; SMS through the SMS integration; both simulate when not live.
 */
function send_message(string $channel, string $to, ?string $subject, string $body, array $o = []): array {
    $id = insert('com_messages', ['campaign_id' => nullable($o['campaign_id'] ?? null), 'channel' => $channel, 'party_id' => nullable($o['party_id'] ?? null),
        'user_id' => nullable($o['user_id'] ?? null), 'to_address' => mb_substr($to, 0, 190), 'subject' => $subject ? mb_substr($subject, 0, 200) : null, 'body' => $body,
        'related_type' => nullable($o['related_type'] ?? null), 'related_id' => nullable($o['related_id'] ?? null)]);
    try {
        $res = match ($channel) {
            'email' => mailer_send($to, (string)$subject, $body),
            'sms' => sms_send($to, $body),
            default => ['status' => 'sent', 'ref' => null],
        };
        update('com_messages', ['status' => $res['status'], 'provider_ref' => $res['ref'] ?? null, 'attempts' => 1, 'sent_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    } catch (Throwable $e) {
        update('com_messages', ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'attempts' => 1], 'id = :id', ['id' => $id]);
    }
    return one("SELECT * FROM com_messages WHERE id = ?", [$id]);
}

/** Render + send a template to a party on its channels (email/sms/portal). */
function send_templated(string $code, array $party, array $vars = [], ?string $channel = null, ?string $relType = null, ?int $relId = null, ?int $campaignId = null): int {
    $t = one("SELECT * FROM com_templates WHERE code = ?", [$code]);
    if (!$t) return 0;
    return send_rendered($t['channel'], $t['subject'], $t['body_email'], $t['body_sms'], $party, $vars, $channel, $relType, $relId, $campaignId);
}
function send_rendered(string $tplChannel, ?string $subject, ?string $bodyEmail, ?string $bodySms, array $party, array $vars, ?string $channel = null, ?string $relType = null, ?int $relId = null, ?int $campaignId = null): int {
    $v = $vars + party_vars($party);
    $ch = $channel ?? $tplChannel; $sent = 0; $o = ['party_id' => (int)$party['id'], 'related_type' => $relType, 'related_id' => $relId, 'campaign_id' => $campaignId];
    $subj = render_placeholders((string)$subject, $v);
    if (in_array($ch, ['email', 'both'], true) && $party['email'] && $bodyEmail) { send_message('email', $party['email'], $subj, email_wrap($subj, render_placeholders($bodyEmail, $v)), $o); $sent++; }
    if (in_array($ch, ['sms', 'both'], true) && $party['phone'] && ($bodySms || $bodyEmail)) { send_message('sms', $party['phone'], null, render_placeholders($bodySms ?: strip_tags($bodyEmail), $v), $o); $sent++; }
    if ($ch === 'portal' || $campaignId) {
        portal_notify((int)$party['id'], 'message', $subj ?: 'New message', excerpt(strip_tags(render_placeholders($bodySms ?: (string)$bodyEmail, $v)), 900), 'fa-envelope', portal_page('inbox'));
        if ($ch === 'portal') { send_message('portal', 'portal:' . $party['id'], $subj, render_placeholders((string)($bodyEmail ?: $bodySms), $v), $o); $sent++; }
    }
    return $sent;
}

/**
 * Audience → parties. $a: type (all|tenants|owners|arrears|custom|lease_expiring|property),
 * property_ids[], block_ids[], min_arrears, party_ids[], days
 */
function audience_parties(array $a): array {
    $w = ['p.deleted_at IS NULL']; $p = [];
    $type = $a['type'] ?? 'all';
    $props = array_filter(array_map('intval', (array)($a['property_ids'] ?? [])));
    $propSql = $props ? ' AND x.property_id IN (' . in_ints($props) . ')' : '';
    if ($type === 'custom') return all("SELECT * FROM prpt_parties p WHERE p.id IN (" . in_ints((array)($a['party_ids'] ?? [])) . ")");
    if ($type === 'tenants') $w[] = "EXISTS (SELECT 1 FROM bln_lease_parties lp JOIN bln_leases x ON x.id = lp.lease_id WHERE lp.party_id = p.id AND lp.role IN ('tenant','co_tenant') AND x.status IN ('active','notice') $propSql)";
    elseif ($type === 'owners') $w[] = "EXISTS (SELECT 1 FROM prpt_ownerships x WHERE x.party_id = p.id AND (x.end_date IS NULL OR x.end_date >= CURDATE()) $propSql)";
    elseif ($type === 'arrears') { $w[] = "(SELECT COALESCE(SUM(x.balance),0) FROM bln_invoices x WHERE x.party_id = p.id AND x.status IN ('issued','partially_paid','overdue') AND x.due_date < CURDATE() $propSql) >= :minarr"; $p['minarr'] = (float)($a['min_arrears'] ?? 1); }
    elseif ($type === 'lease_expiring') { $w[] = "EXISTS (SELECT 1 FROM bln_lease_parties lp JOIN bln_leases x ON x.id = lp.lease_id WHERE lp.party_id = p.id AND x.status = 'active' AND x.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY) $propSql)"; $p['days'] = (int)($a['days'] ?? 60); }
    else $w[] = "(EXISTS (SELECT 1 FROM bln_lease_parties lp JOIN bln_leases x ON x.id = lp.lease_id WHERE lp.party_id = p.id AND x.status IN ('active','notice') $propSql) OR EXISTS (SELECT 1 FROM prpt_ownerships x WHERE x.party_id = p.id $propSql))";
    return all("SELECT p.* FROM prpt_parties p WHERE " . implode(' AND ', $w) . " ORDER BY p.display_name", $p);
}
function campaign_send(int $campaignId): array {
    $c = one("SELECT * FROM com_campaigns WHERE id = ?", [$campaignId]);
    if (!$c || in_array($c['status'], ['sent', 'cancelled'], true)) throw new RuntimeException('Campaign is not sendable.');
    update('com_campaigns', ['status' => 'sending'], 'id = :id', ['id' => $campaignId]);
    $parties = audience_parties(json_decode($c['audience'], true) ?: []);
    $sent = 0; $failed = 0;
    foreach ($parties as $pt) {
        $n = send_rendered($c['channel'], $c['subject'], $c['body'], strip_tags((string)$c['body']), $pt, party_unit_vars((int)$pt['id']), null, 'campaign', $campaignId, $campaignId);
        $n ? $sent += $n : $failed++;
    }
    update('com_campaigns', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'), 'recipients_count' => count($parties), 'sent_count' => $sent, 'failed_count' => $failed], 'id = :id', ['id' => $campaignId]);
    audit('comms', 'send', 'campaign', $campaignId, "Campaign \"{$c['name']}\" sent to " . count($parties) . ' recipients');
    return ['recipients' => count($parties), 'sent' => $sent, 'failed' => $failed];
}

/** Reminder rules (cron): due soon, overdue, lease expiry, check-ins */
function run_reminders(): int {
    $n = 0;
    foreach (all("SELECT r.*, t.code AS tpl FROM com_reminder_rules r JOIN com_templates t ON t.id = r.template_id WHERE r.is_active = 1") as $r) {
        $off = (int)$r['offset_days'];
        if (in_array($r['trigger_type'], ['invoice_due', 'invoice_overdue'], true)) {
            $rows = all("SELECT i.*, u.unit_no FROM bln_invoices i LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE i.balance >= ? AND i.status IN ('issued','partially_paid','overdue')
                         AND DATE_ADD(i.due_date, INTERVAL ? DAY) = CURDATE() AND NOT EXISTS (SELECT 1 FROM bln_invoice_events e WHERE e.invoice_id = i.id AND e.event = 'reminder' AND DATE(e.created_at) = CURDATE())",
                         [(float)($r['min_amount'] ?? 1), $off]);
            foreach ($rows as $inv) {
                $pt = one("SELECT * FROM prpt_parties WHERE id = ?", [$inv['party_id']]);
                $n += send_templated($r['tpl'], $pt, ['invoice_no' => $inv['invoice_no'], 'amount' => $inv['total'], 'balance' => $inv['balance'], 'due_date' => fdate($inv['due_date']), 'unit_no' => $inv['unit_no']], $r['channel'], 'invoice', (int)$inv['id']);
                invoice_event((int)$inv['id'], 'reminder', 'Reminder sent (' . $r['name'] . ')');
            }
        } elseif ($r['trigger_type'] === 'lease_expiry') {
            foreach (all("SELECT l.*, u.unit_no FROM bln_leases l JOIN prpt_units u ON u.id = l.unit_id WHERE l.status = 'active' AND l.end_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)", [-$off ?: 60]) as $l)
                foreach (all("SELECT p.* FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = ?", [$l['id']]) as $pt)
                    $n += send_templated($r['tpl'], $pt, ['lease_no' => $l['lease_no'], 'unit_no' => $l['unit_no'], 'end_date' => fdate($l['end_date'])], $r['channel'], 'lease', (int)$l['id']);
        } elseif ($r['trigger_type'] === 'booking_checkin') {
            foreach (all("SELECT b.*, s.title, s.check_in_time, s.access_instructions, s.wifi_name, s.wifi_password FROM sst_bookings b JOIN sst_listings s ON s.id = b.listing_id WHERE b.status = 'confirmed' AND b.check_in = DATE_ADD(CURDATE(), INTERVAL ? DAY)", [-$off ?: 1]) as $b) {
                $pt = one("SELECT * FROM prpt_parties WHERE id = ?", [$b['guest_party_id']]);
                $n += send_templated($r['tpl'], $pt, ['booking_no' => $b['booking_no'], 'listing' => $b['title'], 'check_in' => fdate($b['check_in']), 'check_in_time' => substr($b['check_in_time'], 0, 5),
                    'access_instructions' => $b['access_instructions'], 'wifi' => $b['wifi_name'] . ' / ' . $b['wifi_password']], $r['channel'], 'booking', (int)$b['id']);
            }
        }
        q("UPDATE com_reminder_rules SET last_run_at = NOW() WHERE id = ?", [$r['id']]);
    }
    return $n;
}

/** Unit / account placeholders for a person (their main active account, else first owned unit). */
function party_unit_vars(int $partyId): array {
    $l = one("SELECT l.lease_no, l.end_date, u.unit_no, pr.name AS property_name FROM bln_lease_parties lp JOIN bln_leases l ON l.id = lp.lease_id JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id
              WHERE lp.party_id = ? AND l.status IN ('active','notice') ORDER BY lp.is_primary DESC, lp.role = 'owner' LIMIT 1", [$partyId]);
    if (!$l && ($uid = array_key_first(party_owned_units($partyId)))) $l = one("SELECT NULL AS lease_no, NULL AS end_date, u.unit_no, pr.name AS property_name FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.id = ?", [$uid]);
    $arr = (float)val("SELECT COALESCE(SUM(balance),0) FROM bln_invoices WHERE party_id = ? AND status IN ('issued','partially_paid','overdue') AND due_date < CURDATE()", [$partyId]);
    return ['unit_no' => $l['unit_no'] ?? '', 'property_name' => $l['property_name'] ?? '', 'lease_no' => $l['lease_no'] ?? '', 'end_date' => !empty($l['end_date']) ? fdate($l['end_date']) : '', 'arrears' => $arr, 'account_ref' => $l['unit_no'] ?? ''];
}
