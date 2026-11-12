<?php
/** Short-stay AJAX actions */
return [
    'quote' => ['shortstay.view', function () {
        $lid = (int)input_int('listing_id'); $in = (string)input_date('check_in'); $out = (string)input_date('check_out');
        if (!$lid || !$in || !$out) json_ok(['ok' => false]);
        try { $q = sst_quote($lid, $in, $out, (int)input_int('adults', 1) + (int)input_int('children', 0), (string)input('channel', 'direct')); }
        catch (ValidationError $e) { json_ok(['ok' => false, 'error' => implode(' ', $e->errors)]); }
        json_ok(['ok' => true, 'available' => sst_is_available($lid, $in, $out, input_int('ignore')), 'q' => $q]);
    }],
    'create-booking' => ['shortstay.manage', function () {
        validate($_POST, ['listing_id' => 'required', 'check_in' => 'required|date', 'check_out' => 'required|date', 'adults' => 'required']);
        if (!input_int('guest_party_id') && !trim((string)input('guest_first_name'))) throw new ValidationError(['guest_first_name' => 'Choose an existing guest or enter the guest\'s name.']);
        $b = sst_create_booking($_POST);
        json_ok(['redirect' => page('shortstay/booking', ['id' => $b['id']])], 'Booking ' . $b['booking_no'] . ' confirmed.');
    }],
    'booking-status' => ['shortstay.manage', function () {
        $b = one("SELECT * FROM sst_bookings WHERE id = ?", [input_int('id')]); $s = (string)input('status');
        if (!$b) json_fail('Not found', 404);
        $row = ['status' => $s];
        if ($s === 'checked_in') { $row['checked_in_at'] = date('Y-m-d H:i:s'); if ($idn = input('id_number')) q("UPDATE prpt_parties SET id_number = COALESCE(id_number, ?) WHERE id = ?", [$idn, $b['guest_party_id']]); }
        if ($s === 'checked_out') { $row['checked_out_at'] = date('Y-m-d H:i:s'); q("UPDATE sst_housekeeping SET scheduled_for = NOW() WHERE booking_id = ? AND task_type = 'turnover' AND status = 'pending' AND scheduled_for > NOW()", [$b['id']]); }
        if ($s === 'cancelled' || $s === 'no_show') {
            $row += ['cancelled_at' => date('Y-m-d H:i:s'), 'cancel_reason' => input('reason')];
            $inv = $b['invoice_id'] ? one("SELECT * FROM bln_invoices WHERE id = ?", [$b['invoice_id']]) : null;
            if ($inv && (float)$inv['amount_paid'] == 0.0 && $s === 'cancelled') invoice_void((int)$inv['id'], 'Booking cancelled');
            q("UPDATE sst_housekeeping SET status = 'done', notes = 'Booking cancelled' WHERE booking_id = ? AND status = 'pending'", [$b['id']]);
        }
        if ($s === 'confirmed' || $s === 'tentative') { if (!sst_is_available((int)$b['listing_id'], $b['check_in'], $b['check_out'], (int)$b['id'])) json_fail('Dates are no longer available.'); }
        update('sst_bookings', $row, 'id = :id', ['id' => $b['id']]);
        audit('shortstay', $s, 'booking', $b['id'], 'Booking ' . $b['booking_no'] . ' → ' . $s);
        json_ok([], 'Booking ' . str_replace('_', ' ', $s) . '.');
    }],
    'review' => ['shortstay.manage', function () { update('sst_bookings', ['review_rating' => input_int('rating'), 'review_text' => input('text')], 'id = :id', ['id' => input_int('id')]); json_ok([], 'Saved.'); }],
    'send-checkin' => ['shortstay.manage', function () {
        $b = one("SELECT b.*, s.title, s.check_in_time, s.access_instructions, s.wifi_name, s.wifi_password FROM sst_bookings b JOIN sst_listings s ON s.id = b.listing_id WHERE b.id = ?", [input_int('id')]);
        $g = one("SELECT * FROM prpt_parties WHERE id = ?", [$b['guest_party_id']]);
        $n = send_templated('booking_checkin', $g, ['check_in' => fdate($b['check_in']), 'check_in_time' => substr($b['check_in_time'], 0, 5), 'access_instructions' => nl2br(e((string)$b['access_instructions'])), 'wifi' => trim($b['wifi_name'] . ' / ' . $b['wifi_password'], ' /'), 'listing' => $b['title']], null, 'booking', (int)$b['id']);
        json_ok([], $n ? 'Check-in instructions sent.' : 'Guest has no email or phone on file.');
    }],
    'save-listing' => ['shortstay.manage', function () {
        $d = validate($_POST, ['unit_id' => 'required', 'title' => 'required', 'base_rate' => 'required|numeric', 'max_guests' => 'required']);
        $cols = ['title', 'max_guests', 'beds', 'base_rate', 'weekend_rate', 'weekly_discount_pct', 'monthly_discount_pct', 'cleaning_fee', 'security_deposit', 'extra_guest_fee', 'extra_guest_after', 'min_nights', 'max_nights', 'check_in_time', 'check_out_time', 'house_rules', 'wifi_name', 'wifi_password', 'access_instructions', 'tourism_levy_pct', 'vat_pct', 'owner_share_pct', 'status'];
        $row = []; foreach ($cols as $c) if (array_key_exists($c, $_POST)) $row[$c] = nullable(is_string($_POST[$c]) ? trim($_POST[$c]) : $_POST[$c]);
        foreach (['max_guests', 'min_nights'] as $c) if (isset($row[$c])) $row[$c] = (int)$row[$c];
        if ($id = input_int('id')) update('sst_listings', $row, 'id = :id', ['id' => $id]);
        else {
            if (val("SELECT 1 FROM sst_listings WHERE unit_id = ? AND status <> 'archived'", [$d['unit_id']])) throw new ValidationError(['unit_id' => 'This unit already has a short-stay listing.']);
            $id = insert('sst_listings', $row + ['unit_id' => (int)$d['unit_id'], 'ical_export_token' => bin2hex(random_bytes(16)), 'status' => 'active']);
            q("UPDATE prpt_units SET purpose = 'short_stay' WHERE id = ?", [$d['unit_id']]);
        }
        audit('shortstay', 'save', 'listing', $id, 'Saved listing ' . $row['title']);
        json_ok(['redirect' => page('shortstay/listing', ['id' => $id])], 'Listing saved.');
    }],
    'block' => ['shortstay.manage', function () {
        $d = validate($_POST, ['listing_id' => 'required', 'start_date' => 'required|date', 'end_date' => 'required|date']);
        if ($d['end_date'] < $d['start_date']) throw new ValidationError(['end_date' => 'End must be after start.']);
        if (!sst_is_available((int)$d['listing_id'], $d['start_date'], date('Y-m-d', strtotime($d['end_date'] . ' +1 day')))) throw new ValidationError(['start_date' => 'There are bookings in that range.']);
        insert('sst_blocked_dates', ['listing_id' => (int)$d['listing_id'], 'start_date' => $d['start_date'], 'end_date' => date('Y-m-d', strtotime($d['end_date'] . ' +1 day')), 'reason' => input('reason', 'other'), 'note' => input('note')]);
        json_ok([], 'Dates blocked.');
    }],
    'unblock' => ['shortstay.manage', function () { q("DELETE FROM sst_blocked_dates WHERE id = ?", [input_int('id')]); json_ok([], 'Unblocked.'); }],
    'ical-sync' => ['shortstay.manage', function () {
        $n = 0; $err = [];
        foreach (col("SELECT id FROM sst_channels WHERE listing_id = ? AND is_active = 1 AND ical_import_url IS NOT NULL AND ical_import_url <> ''", [input_int('listing_id')]) as $cid) { try { $r = sst_ical_import((int)$cid); $n += is_array($r) ? (int)($r['imported'] ?? 0) : (int)$r; } catch (Throwable $e) { $err[] = $e->getMessage(); } }
        json_ok([], $err ? 'Sync issues: ' . implode('; ', $err) : "Synced — $n blocked range(s) imported.");
    }],
    'hk-update' => ['shortstay.view', function () {
        $h = one("SELECT * FROM sst_housekeeping WHERE id = ?", [input_int('id')]); if (!$h) json_fail('Not found', 404);
        $row = [];
        if (($s = input('status')) !== null) { $row['status'] = $s; if ($s === 'done') $row['completed_at'] = date('Y-m-d H:i:s'); }
        if (input('assigned_to') !== null) $row['assigned_to'] = input_int('assigned_to');
        if (input('notes') !== null) $row['notes'] = input('notes');
        update('sst_housekeeping', $row, 'id = :id', ['id' => $h['id']]);
        if (($row['status'] ?? '') === 'issue') staff_notify_perm('shortstay.manage', 'housekeeping', 'Housekeeping issue reported', (string)input('notes'), 'fa-broom', page('shortstay/housekeeping'));
        json_ok([], 'Updated.');
    }],
    'hk-create' => ['shortstay.manage', function () {
        $d = validate($_POST, ['listing_id' => 'required', 'task_type' => 'required', 'scheduled_for' => 'required']);
        insert('sst_housekeeping', ['listing_id' => (int)$d['listing_id'], 'task_type' => $d['task_type'], 'scheduled_for' => date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)$d['scheduled_for']))), 'assigned_to' => input_int('assigned_to'), 'notes' => input('notes')]);
        json_ok([], 'Task scheduled.');
    }],
];
