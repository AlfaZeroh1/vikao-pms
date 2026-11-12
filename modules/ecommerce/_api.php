<?php
/** Ecommerce (vikao.io marketplace) management actions */
if (!ecom_ok()) json_fail('The marketplace database is not reachable — check ecom_db in config.php.', 503);
return [
    'approve' => ['ecommerce.moderate', function () { foreach (array_map('intval', input_array('ids') ?: [input_int('id')]) as $id) ecom_approve($id); json_ok([], 'Approved — the seller has been notified.'); }],
    'reject' => ['ecommerce.moderate', function () {
        $reason = trim((string)input('reason')); if ($reason === '') throw new ValidationError(['reason' => 'Tell the seller what to fix.']);
        foreach (array_map('intval', input_array('ids') ?: [input_int('id')]) as $id) ecom_reject($id, $reason);
        json_ok([], 'Declined — the seller can edit and resubmit.');
    }],
    'listing-status' => ['ecommerce.moderate', function () { $s = (string)input('status'); if (!in_array($s, ['active', 'paused', 'closed', 'sold', 'expired'], true)) json_fail('Bad status'); ecom_set_listing_status((int)input_int('id'), $s); audit('ecommerce', $s, 'ecom_listing', input_int('id'), 'Listing set to ' . $s); json_ok([], 'Listing ' . $s . '.'); }],
    'premium' => ['ecommerce.moderate', function () { ecom_set_premium((int)input_int('id'), (bool)input('on')); json_ok([], input('on') ? 'Promoted to premium for 30 days.' : 'Premium removed.'); }],
    'user-update' => ['ecommerce.accounts', function () {
        $d = array_filter(['status' => input('status'), 'membership_tier_id' => input('membership_tier_id'), 'badge' => input('badge')], fn($v) => $v !== null);
        ecom_user_update((int)input_int('id'), $d); json_ok([], 'Account updated.');
    }],
    'ack-event' => ['ecommerce.view', function () { $lead = ecom_ack_event((int)input_int('id')); json_ok($lead ? ['redirect' => page('sales/lead', ['id' => $lead])] : [], $lead ? 'Acknowledged — sales lead created.' : 'Acknowledged.'); }],
    'tier-save' => ['ecommerce.accounts', function () {
        eq("UPDATE membership_tiers SET monthly_price = ?, max_active_ads = ? WHERE id = ?", [(float)input_num('monthly_price', 0), input_int('max_active_ads'), input_int('id')]);
        insert('ecm_sync_log', ['action' => 'tier.update', 'ecom_ref' => (string)input_int('id'), 'details' => json_encode($_POST), 'user_id' => staff_id()]);
        json_ok([], 'Plan updated.');
    }],
    'method-toggle' => ['ecommerce.integrations', function () {
        $f = input('field') === 'is_simulated' ? 'is_simulated' : 'is_active';
        eq("UPDATE payment_methods SET $f = ? WHERE id = ?", [(int)!!input('on'), input_int('id')]);
        insert('ecm_sync_log', ['action' => 'payment_method.' . $f, 'ecom_ref' => (string)input_int('id'), 'details' => json_encode(['on' => (int)!!input('on')]), 'user_id' => staff_id()]);
        json_ok([], 'Payment method updated.');
    }],
    'locations' => ['ecommerce.official', function () {
        $q = '%' . trim((string)input('q', '')) . '%';
        json_ok(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['full_name'] ?: $r['name'], 'sub' => $r['level'] ?? ''], eall("SELECT id, name, full_name, level FROM locations WHERE name LIKE ? OR full_name LIKE ? ORDER BY CHAR_LENGTH(full_name) LIMIT 20", [$q, $q]))]);
    }],
    'push-unit' => ['ecommerce.official', function () {
        $d = validate($_POST, ['unit_id' => 'required', 'title' => 'required|min:10', 'description_html' => 'required', 'price' => 'required|numeric', 'category_slug' => 'required', 'ecom_location_id' => 'required']);
        $photos = [];
        if (!empty($_FILES['photos']['name']) && is_array($_FILES['photos']['name'])) {
            foreach ($_FILES['photos']['tmp_name'] as $i => $tmp) {
                if (($_FILES['photos']['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) continue;
                $mime = mime_content_type($tmp) ?: ''; if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new ValidationError(['photos' => 'Photos must be JPG, PNG or WebP.']);
                if (filesize($tmp) > 8 * 1024 * 1024) throw new ValidationError(['photos' => 'Each photo must be under 8 MB.']);
                $photos[] = $tmp;
            }
        }
        foreach (input_array('existing_photos') as $rel) { $abs = upload_abs((string)$rel); if ($abs && is_file($abs)) $photos[] = $abs; }
        $packages = [];
        foreach ((array)($_POST['packages'] ?? []) as $p) if (!empty($p['name']) && !empty($p['total_price'])) $packages[] = $p;
        $uuid = ecom_push_unit((int)$d['unit_id'], ['title' => $d['title'], 'description_html' => $d['description_html'], 'price' => (float)$d['price'], 'reservation_fee' => (float)input_num('reservation_fee', 0),
            'category_slug' => $d['category_slug'], 'ecom_location_id' => (int)$d['ecom_location_id'], 'billing_period' => input('billing_period', 'month'), 'photos' => $photos, 'packages' => $packages]);
        json_ok(['url' => ecom_url('ad.php?id=' . $uuid), 'redirect' => page('ecommerce/listings', ['official' => 1, 'status' => 'active'])], 'Published on vikao.io as an Official listing.');
    }],
];
