<?php
/** Short-stay engine: availability, dynamic quote (seasons, weekends, LOS discounts, levies), bookings, iCal. */

function sst_is_available(int $listingId, string $in, string $out, ?int $ignoreBookingId = null): bool {
    $b = (int)val("SELECT COUNT(*) FROM sst_bookings WHERE listing_id = ? AND status IN ('tentative','confirmed','checked_in') AND check_in < ? AND check_out > ?" . ($ignoreBookingId ? ' AND id <> ' . (int)$ignoreBookingId : ''), [$listingId, $out, $in]);
    $x = (int)val("SELECT COUNT(*) FROM sst_blocked_dates WHERE listing_id = ? AND start_date < ? AND end_date > ?", [$listingId, $out, $in]);
    return $b + $x === 0;
}

/** Price a stay night by night. */
function sst_quote(int $listingId, string $in, string $out, int $guests = 1, ?string $channel = 'direct'): array {
    $s = one("SELECT * FROM sst_listings WHERE id = ?", [$listingId]);
    if (!$s) throw new RuntimeException('Listing not found.');
    $nights = (int)((strtotime($out) - strtotime($in)) / 86400);
    if ($nights < 1) throw new ValidationError(['check_out' => 'Check-out must be after check-in.']);
    $rules = all("SELECT * FROM sst_rate_rules WHERE listing_id = ? AND start_date < ? AND end_date >= ?", [$listingId, $out, $in]);
    $minNights = (int)$s['min_nights']; $acc = 0.0; $breakdown = [];
    for ($i = 0; $i < $nights; $i++) {
        $d = date('Y-m-d', strtotime("$in +$i day")); $rate = (float)$s['base_rate']; $label = 'Base';
        if ($s['weekend_rate'] && in_array(date('N', strtotime($d)), ['5', '6'], true)) { $rate = (float)$s['weekend_rate']; $label = 'Weekend'; }
        foreach ($rules as $r) if ($d >= $r['start_date'] && $d <= $r['end_date']) { $rate = (float)$r['nightly_rate']; $label = $r['name']; if ($r['min_nights']) $minNights = max($minNights, (int)$r['min_nights']); }
        $acc += $rate; $breakdown[] = ['date' => $d, 'rate' => $rate, 'label' => $label];
    }
    if ($nights < $minNights) throw new ValidationError(['check_out' => "Minimum stay is $minNights nights for these dates."]);
    if ($s['max_nights'] && $nights > (int)$s['max_nights']) throw new ValidationError(['check_out' => "Maximum stay is {$s['max_nights']} nights."]);
    if ($guests > (int)$s['max_guests']) throw new ValidationError(['adults' => "This unit sleeps at most {$s['max_guests']} guests."]);
    $discount = 0.0;
    if ($nights >= 28 && $s['monthly_discount_pct']) $discount = $acc * (float)$s['monthly_discount_pct'] / 100;
    elseif ($nights >= 7 && $s['weekly_discount_pct']) $discount = $acc * (float)$s['weekly_discount_pct'] / 100;
    $extra = ($s['extra_guest_fee'] && $s['extra_guest_after'] && $guests > (int)$s['extra_guest_after']) ? ($guests - (int)$s['extra_guest_after']) * (float)$s['extra_guest_fee'] * $nights : 0;
    $taxBase = $acc - $discount + $extra + (float)$s['cleaning_fee'];
    $taxes = round2($taxBase * ((float)$s['tourism_levy_pct'] + (float)$s['vat_pct']) / 100);
    $total = round2($taxBase + $taxes);
    $commPct = $channel && $channel !== 'direct' ? (float)val("SELECT commission_pct FROM sst_channels WHERE listing_id = ? AND channel = ?", [$listingId, $channel]) : 0;
    return ['nights' => $nights, 'accommodation' => round2($acc), 'discount' => round2($discount), 'extra_fees' => round2($extra), 'cleaning_fee' => (float)$s['cleaning_fee'],
            'taxes' => $taxes, 'total' => $total, 'security_deposit' => (float)$s['security_deposit'], 'channel_commission' => round2($total * $commPct / 100),
            'avg_nightly' => round2($acc / $nights), 'breakdown' => $breakdown];
}

/** Create a booking + its invoice (so payments/consume/statements work the same as rent). */
function sst_create_booking(array $d): array {
    $listingId = (int)$d['listing_id'];
    if (!sst_is_available($listingId, $d['check_in'], $d['check_out'])) throw new ValidationError(['check_in' => 'Those dates are not available.']);
    $q = sst_quote($listingId, $d['check_in'], $d['check_out'], (int)$d['adults'] + (int)($d['children'] ?? 0), $d['channel'] ?? 'direct');
    if (isset($d['total_override']) && $d['total_override'] !== '') { $q['total'] = round2((float)$d['total_override']); }
    $s = one("SELECT s.*, u.property_id, u.unit_no FROM sst_listings s JOIN prpt_units u ON u.id = s.unit_id WHERE s.id = ?", [$listingId]);
    return tx(function () use ($d, $q, $s, $listingId) {
        $guestId = (int)($d['guest_party_id'] ?? 0);
        if (!$guestId) $guestId = party_create(['first_name' => $d['guest_first_name'], 'last_name' => $d['guest_last_name'] ?? '', 'email' => $d['guest_email'] ?? null, 'phone' => $d['guest_phone'] ?? null,
            'id_type' => nullable($d['guest_id_type'] ?? null), 'id_number' => $d['guest_id_number'] ?? null, 'nationality' => $d['guest_nationality'] ?? null, 'is_guest' => 1]);
        else q("UPDATE prpt_parties SET is_guest = 1 WHERE id = ?", [$guestId]);
        $id = insert('sst_bookings', ['uuid' => uuid4(), 'booking_no' => next_no('booking'), 'listing_id' => $listingId, 'guest_party_id' => $guestId, 'channel' => $d['channel'] ?? 'direct',
            'channel_ref' => nullable($d['channel_ref'] ?? null), 'check_in' => $d['check_in'], 'check_out' => $d['check_out'], 'nights' => $q['nights'], 'adults' => (int)$d['adults'],
            'children' => (int)($d['children'] ?? 0), 'accommodation_total' => $q['accommodation'], 'cleaning_fee' => $q['cleaning_fee'], 'extra_fees' => $q['extra_fees'], 'discount' => $q['discount'],
            'taxes' => $q['taxes'], 'channel_commission' => $q['channel_commission'], 'total' => $q['total'], 'security_deposit' => $q['security_deposit'],
            'status' => $d['status'] ?? 'confirmed', 'special_requests' => nullable($d['special_requests'] ?? null), 'arrival_time' => nullable($d['arrival_time'] ?? null), 'created_by' => staff_id()]);
        $bt = bill_type_by_code('SST_ACC'); $btc = bill_type_by_code('SST_CLEAN');
        $lines = [['bill_type_id' => (int)$bt['id'], 'description' => "Accommodation {$q['nights']} night(s) · " . fdate($d['check_in']) . ' → ' . fdate($d['check_out']), 'quantity' => $q['nights'], 'unit_price' => round(($q['accommodation'] - $q['discount'] + $q['extra_fees']) / $q['nights'], 4)]];
        if ($q['cleaning_fee'] > 0) $lines[] = ['bill_type_id' => (int)$btc['id'], 'description' => 'Cleaning fee', 'quantity' => 1, 'unit_price' => $q['cleaning_fee']];
        if ($q['taxes'] > 0) $lines[] = ['bill_type_id' => (int)$bt['id'], 'description' => 'Tourism levy & taxes', 'quantity' => 1, 'unit_price' => $q['taxes']];
        if (isset($d['total_override']) && $d['total_override'] !== '') $lines = [['bill_type_id' => (int)$bt['id'], 'description' => "Stay {$q['nights']} night(s) (agreed rate)", 'quantity' => 1, 'unit_price' => $q['total']]];
        $inv = invoice_create(['property_id' => (int)$s['property_id'], 'unit_id' => (int)$s['unit_id'], 'party_id' => $guestId, 'booking_id' => $id, 'issue_date' => date('Y-m-d'),
            'due_date' => min($d['check_in'], date('Y-m-d', strtotime('+2 days'))), 'source' => 'booking', 'notes' => 'Booking ' . $id], $lines);
        update('sst_bookings', ['invoice_id' => $inv['id']], 'id = :id', ['id' => $id]);
        insert('sst_housekeeping', ['listing_id' => $listingId, 'booking_id' => $id, 'task_type' => 'turnover', 'scheduled_for' => $d['check_out'] . ' ' . substr($s['check_out_time'], 0, 5) . ':00']);
        $b = one("SELECT * FROM sst_bookings WHERE id = ?", [$id]);
        audit('shortstay', 'create', 'booking', $id, "Booking {$b['booking_no']} {$s['unit_no']} " . fdate($d['check_in']) . ' → ' . fdate($d['check_out']));
        $guest = one("SELECT * FROM prpt_parties WHERE id = ?", [$guestId]);
        send_templated('booking_confirmation', $guest, ['booking_no' => $b['booking_no'], 'listing' => $s['title'], 'check_in' => fdate($b['check_in']), 'check_out' => fdate($b['check_out']),
            'nights' => $b['nights'], 'amount' => $b['total'], 'check_in_time' => substr($s['check_in_time'], 0, 5)], null, 'booking', $id);
        return $b;
    });
}

/** iCal export (for Airbnb / Booking.com to import our availability) */
function sst_ical(int $listingId): string {
    $s = one("SELECT * FROM sst_listings WHERE id = ?", [$listingId]);
    $out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Vikao PMS//Short Stay//EN\r\nCALSCALE:GREGORIAN\r\n";
    $ev = function ($uid, $start, $end, $summary) { return "BEGIN:VEVENT\r\nUID:$uid@vikao\r\nDTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART;VALUE=DATE:" . date('Ymd', strtotime($start)) . "\r\nDTEND;VALUE=DATE:" . date('Ymd', strtotime($end)) . "\r\nSUMMARY:$summary\r\nEND:VEVENT\r\n"; };
    foreach (all("SELECT * FROM sst_bookings WHERE listing_id = ? AND status IN ('tentative','confirmed','checked_in') AND check_out >= CURDATE()", [$listingId]) as $b) $out .= $ev($b['uuid'], $b['check_in'], $b['check_out'], 'Reserved');
    foreach (all("SELECT * FROM sst_blocked_dates WHERE listing_id = ? AND end_date >= CURDATE() AND reason <> 'ical_import'", [$listingId]) as $x) $out .= $ev('blk' . $x['id'], $x['start_date'], $x['end_date'], 'Not available');
    return $out . "END:VCALENDAR\r\n";
}
/** iCal import (pulls OTA reservations as blocked dates) */
function sst_ical_import(int $channelId): int {
    $c = one("SELECT * FROM sst_channels WHERE id = ?", [$channelId]);
    if (!$c || !$c['ical_import_url']) return 0;
    $res = http_request('GET', $c['ical_import_url'], [], null, 'ical');
    if (!$res['ok']) { update('sst_channels', ['last_sync_status' => 'Failed: HTTP ' . $res['status']], 'id = :id', ['id' => $channelId]); return 0; }
    preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $res['body'], $m); $n = 0;
    q("DELETE FROM sst_blocked_dates WHERE listing_id = ? AND reason = 'ical_import' AND note = ?", [$c['listing_id'], $c['channel']]);
    foreach ($m[1] as $evt) {
        if (!preg_match('/DTSTART[^:]*:(\d{8})/', $evt, $s) || !preg_match('/DTEND[^:]*:(\d{8})/', $evt, $e)) continue;
        preg_match('/UID:(.+)/', $evt, $u);
        insert('sst_blocked_dates', ['listing_id' => $c['listing_id'], 'start_date' => date('Y-m-d', strtotime($s[1])), 'end_date' => date('Y-m-d', strtotime($e[1])), 'reason' => 'ical_import', 'source_uid' => trim($u[1] ?? ''), 'note' => $c['channel']]);
        $n++;
    }
    update('sst_channels', ['last_synced_at' => date('Y-m-d H:i:s'), 'last_sync_status' => "OK · $n events"], 'id = :id', ['id' => $channelId]);
    return $n;
}
