<?php
/**
 * Vikao ecommerce bridge — reads/writes the marketplace DB (config ecom_db) directly.
 * Mirrors the marketplace's own rules (review → active, notifications, email outbox).
 */
function ecom_ok(): bool { static $ok = null; if ($ok !== null) return $ok; try { ecom_db()->query('SELECT 1 FROM listings LIMIT 1'); return $ok = true; } catch (Throwable $e) { return $ok = false; } }
function eq(string $sql, array $p = []): PDOStatement { return q($sql, $p, ecom_db()); }
function eall(string $sql, array $p = []): array { return all($sql, $p, ecom_db()); }
function eone(string $sql, array $p = []): ?array { return one($sql, $p, ecom_db()); }
function eval_(string $sql, array $p = []) { return val($sql, $p, ecom_db()); }
function ecom_url(string $path = ''): string { return rtrim((string)config('ecom_db.site_url'), '/') . '/' . ltrim($path, '/'); }

function ecom_stats(): array {
    $s = [];
    foreach (eall("SELECT status, COUNT(*) n FROM listings WHERE deleted_at IS NULL GROUP BY status") as $r) $s['listings_' . $r['status']] = (int)$r['n'];
    $s['users'] = (int)eval_("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
    $s['users_new_30d'] = (int)eval_("SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $s['official_active'] = (int)eval_("SELECT COUNT(*) FROM listings WHERE is_official = 1 AND status = 'active' AND deleted_at IS NULL");
    $s['orders_paid'] = (int)eval_("SELECT COUNT(*) FROM orders WHERE status IN ('paid','processing','completed')");
    $s['reservation_revenue'] = (float)eval_("SELECT COALESCE(SUM(amount_paid),0) FROM orders WHERE status IN ('paid','processing','completed')");
    $s['open_reports'] = (int)eval_("SELECT COUNT(*) FROM listing_reports WHERE status = 'open'");
    $s['chats_30d'] = (int)eval_("SELECT COUNT(*) FROM conversations WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $s['views_total'] = (int)eval_("SELECT COALESCE(SUM(views_count),0) FROM listings WHERE deleted_at IS NULL");
    $s['tiers'] = eall("SELECT t.name, COUNT(u.id) n FROM membership_tiers t LEFT JOIN users u ON u.membership_tier_id = t.id GROUP BY t.id ORDER BY t.rank_order");
    $s['by_family'] = eall("SELECT family, COUNT(*) n FROM listings WHERE status = 'active' AND deleted_at IS NULL GROUP BY family");
    return $s;
}
function ecom_listings(array $f, int $limit = 50, int $offset = 0): array {
    $w = ['l.deleted_at IS NULL']; $p = [];
    if (!empty($f['status'])) { $w[] = 'l.status = :st'; $p['st'] = $f['status']; }
    if (!empty($f['family'])) { $w[] = 'l.family = :fam'; $p['fam'] = $f['family']; }
    if (!empty($f['official'])) $w[] = 'l.is_official = 1';
    if (!empty($f['q'])) { $w[] = '(l.title LIKE :q OR l.ref_code LIKE :q OR u.display_name LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
    $where = implode(' AND ', $w);
    $rows = eall("SELECT l.id, l.uuid, l.ref_code, l.title, l.family, l.deal_type, l.status, l.price, l.billing_period, l.price_unit, l.is_official, l.is_premium, l.views_count, l.favourites_count, l.chats_count,
        l.submitted_at, l.published_at, l.created_at, l.reservation_fee, l.description_text, u.display_name AS seller, u.email AS seller_email, u.phone AS seller_phone, u.uuid AS seller_uuid,
        c.name AS category, loc.full_name AS location, m.thumb_path,
        (SELECT submission_type FROM listing_reviews r WHERE r.listing_id = l.id ORDER BY r.id DESC LIMIT 1) AS submission_type,
        (SELECT reason FROM listing_reviews r WHERE r.listing_id = l.id AND r.decision = 'rejected' ORDER BY r.id DESC LIMIT 1) AS reject_reason,
        (SELECT COUNT(*) FROM listing_media mm WHERE mm.listing_id = l.id) AS photos,
        (SELECT COUNT(*) FROM listing_reports rp WHERE rp.listing_id = l.id AND rp.status = 'open') AS reports
        FROM listings l JOIN users u ON u.id = l.user_id JOIN categories c ON c.id = l.category_id JOIN locations loc ON loc.id = l.location_id LEFT JOIN listing_media m ON m.id = l.cover_media_id
        WHERE $where ORDER BY " . (($f['status'] ?? '') === 'pending' ? 'l.submitted_at ASC' : 'l.updated_at DESC') . " LIMIT $limit OFFSET $offset", $p);
    $total = (int)eval_("SELECT COUNT(*) FROM listings l JOIN users u ON u.id = l.user_id WHERE $where", $p);
    return ['rows' => $rows, 'total' => $total];
}
function ecom_media_url(?string $p): string { return $p ? (preg_match('#^https?://#', $p) ? $p : ecom_url($p)) : ''; }

function _ecom_notify(int $userId, string $type, string $title, string $body, string $icon, string $link): void {
    eq("INSERT INTO notifications (user_id, type, title, body, icon, link_url) VALUES (?,?,?,?,?,?)", [$userId, $type, $title, $body, $icon, $link]);
}
function ecom_approve(int $listingId): void {
    tx(function () use ($listingId) {
        $l = eone("SELECT * FROM listings WHERE id = ?", [$listingId]);
        if (!$l) throw new RuntimeException('Listing not found.');
        $exp = $l['is_official'] ? null : date('Y-m-d H:i:s', strtotime('+60 days'));
        eq("UPDATE listings SET status = 'active', published_at = COALESCE(published_at, NOW()), expires_at = ?, closed_at = NULL WHERE id = ?", [$exp, $listingId]);
        eq("UPDATE listing_reviews SET decision = 'approved', reviewer_source = 'pms', decided_at = NOW() WHERE listing_id = ? AND decision = 'pending'", [$listingId]);
        _ecom_notify((int)$l['user_id'], 'ad_approved', 'Your ad is live 🎉', '"' . $l['title'] . '" was approved and is now visible to buyers.', 'fa-circle-check', ecom_url('ad.php?id=' . $l['uuid']));
        $u = eone("SELECT email, display_name FROM users WHERE id = ?", [$l['user_id']]);
        eq("INSERT INTO email_outbox (to_email, to_name, subject, body_html, template, status, related_type, related_id, sent_at) VALUES (?,?,?,?,?,?,?,?,NOW())",
           [$u['email'], $u['display_name'], 'Your ad is live on Vikao', email_wrap('Your ad is live 🎉', '<p><b>' . e($l['title']) . '</b> is now visible to buyers on Vikao.</p>'), 'ad_approved', 'simulated', 'listing', $listingId]);
        insert('ecm_sync_log', ['action' => 'listing.approve', 'ecom_ref' => $l['uuid'], 'details' => json_encode(['title' => $l['title']]), 'user_id' => staff_id()]);
    }, ecom_db());
    audit('ecommerce', 'approve', 'ecom_listing', $listingId, 'Approved marketplace listing');
}
function ecom_reject(int $listingId, string $reason): void {
    tx(function () use ($listingId, $reason) {
        $l = eone("SELECT * FROM listings WHERE id = ?", [$listingId]);
        eq("UPDATE listings SET status = 'rejected' WHERE id = ?", [$listingId]);
        eq("UPDATE listing_reviews SET decision = 'rejected', reason = ?, reviewer_source = 'pms', decided_at = NOW() WHERE listing_id = ? AND decision = 'pending'", [$reason, $listingId]);
        _ecom_notify((int)$l['user_id'], 'ad_rejected', 'Your ad needs changes', '"' . $l['title'] . '" was declined: ' . $reason, 'fa-circle-exclamation', ecom_url('sell.php?edit=' . $l['uuid']));
        insert('ecm_sync_log', ['action' => 'listing.reject', 'ecom_ref' => $l['uuid'], 'details' => json_encode(['reason' => $reason]), 'user_id' => staff_id()]);
    }, ecom_db());
    audit('ecommerce', 'reject', 'ecom_listing', $listingId, 'Declined: ' . $reason);
}
function ecom_set_listing_status(int $listingId, string $status): void {
    eq("UPDATE listings SET status = ?, closed_at = IF(? IN ('closed','sold'), NOW(), closed_at) WHERE id = ?", [$status, $status, $listingId]);
    insert('ecm_sync_log', ['action' => 'listing.' . $status, 'ecom_ref' => (string)$listingId, 'user_id' => staff_id()]);
}
function ecom_set_premium(int $listingId, bool $on): void { eq("UPDATE listings SET is_premium = ?, premium_until = ? WHERE id = ?", [(int)$on, $on ? date('Y-m-d H:i:s', strtotime('+30 days')) : null, $listingId]); }

function ecom_users(array $f, int $limit = 50): array {
    $w = ['u.deleted_at IS NULL']; $p = [];
    if (!empty($f['q'])) { $w[] = '(u.display_name LIKE :q OR u.email LIKE :q OR u.phone LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
    if (!empty($f['status'])) { $w[] = 'u.status = :st'; $p['st'] = $f['status']; }
    if (!empty($f['tier'])) { $w[] = 't.code = :tier'; $p['tier'] = $f['tier']; }
    return eall("SELECT u.*, t.name AS tier_name, t.code AS tier_code,
        (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id AND l.deleted_at IS NULL) AS ads, (SELECT COUNT(*) FROM listings l WHERE l.user_id = u.id AND l.status = 'active') AS active_ads,
        (SELECT GROUP_CONCAT(b.code) FROM user_badges ub JOIN badges b ON b.id = ub.badge_id WHERE ub.user_id = u.id) AS badges
        FROM users u LEFT JOIN membership_tiers t ON t.id = u.membership_tier_id WHERE " . implode(' AND ', $w) . " ORDER BY u.created_at DESC LIMIT $limit", $p);
}
function ecom_user_update(int $userId, array $d): void {
    if (isset($d['status'])) eq("UPDATE users SET status = ? WHERE id = ?", [$d['status'], $userId]);
    if (isset($d['membership_tier_id'])) eq("UPDATE users SET membership_tier_id = ?, membership_expires_at = DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id = ?", [$d['membership_tier_id'] ?: null, $userId]);
    if (isset($d['badge'])) {
        $bid = (int)eval_("SELECT id FROM badges WHERE code = ?", [$d['badge']]);
        $has = eval_("SELECT 1 FROM user_badges WHERE user_id = ? AND badge_id = ?", [$userId, $bid]);
        $has ? eq("DELETE FROM user_badges WHERE user_id = ? AND badge_id = ?", [$userId, $bid]) : eq("INSERT INTO user_badges (user_id, badge_id) VALUES (?, ?)", [$userId, $bid]);
    }
    insert('ecm_sync_log', ['action' => 'user.update', 'ecom_ref' => (string)$userId, 'details' => json_encode($d), 'user_id' => staff_id()]);
    audit('ecommerce', 'update', 'ecom_user', $userId, 'Marketplace account updated', null, $d);
}
function ecom_orders(array $f = [], int $limit = 100): array {
    $w = ['1=1']; $p = [];
    if (!empty($f['status'])) { $w[] = 'o.status = :st'; $p['st'] = $f['status']; }
    return eall("SELECT o.*, u.display_name AS buyer, (SELECT provider_receipt FROM payments pm WHERE pm.order_id = o.id AND pm.status = 'success' LIMIT 1) AS receipt,
                 (SELECT pm2.is_simulated FROM payments pm2 WHERE pm2.order_id = o.id ORDER BY pm2.id DESC LIMIT 1) AS simulated
                 FROM orders o JOIN users u ON u.id = o.user_id WHERE " . implode(' AND ', $w) . " ORDER BY o.created_at DESC LIMIT $limit", $p);
}
function ecom_payments(int $limit = 100): array {
    return eall("SELECT pm.*, o.order_number, o.contact_name, m.name AS method_name FROM payments pm JOIN orders o ON o.id = pm.order_id JOIN payment_methods m ON m.id = pm.payment_method_id ORDER BY pm.initiated_at DESC LIMIT $limit");
}
/** Marketplace → PMS events (order.paid, listing.submitted) */
function ecom_events(bool $onlyNew = false, int $limit = 50): array {
    return eall("SELECT * FROM pms_events " . ($onlyNew ? "WHERE status = 'delivered' " : '') . "ORDER BY id DESC LIMIT $limit");
}
/** Acknowledge an order.paid event and create a sales lead for the reserved unit. */
function ecom_ack_event(int $eventId): ?int {
    $ev = eone("SELECT * FROM pms_events WHERE id = ?", [$eventId]);
    if (!$ev) return null;
    $leadId = null;
    if ($ev['event_type'] === 'order.paid') {
        $p = json_decode($ev['payload'], true);
        $o = eone("SELECT * FROM orders WHERE id = ?", [$ev['aggregate_id']]);
        $unitId = null;
        if (!empty($p['listing']['uuid'])) $unitId = val("SELECT id FROM prpt_units WHERE ecom_listing_uuid = ?", [$p['listing']['uuid']]);
        if (!val("SELECT 1 FROM sls_leads WHERE ecom_order_number = ?", [$p['order_number']])) {
            $stage = (int)val("SELECT id FROM sls_stages WHERE name LIKE 'Reserv%' LIMIT 1") ?: (int)val("SELECT id FROM sls_stages ORDER BY sort_order LIMIT 1");
            $leadId = insert('sls_leads', ['lead_no' => next_no('lead'), 'full_name' => $p['customer']['name'], 'phone' => $p['customer']['phone'], 'email' => $p['customer']['email'],
                'source' => 'vikao_ecommerce', 'interest' => in_array($p['listing']['deal_type'] ?? '', ['rent'], true) ? 'rent' : 'buy', 'unit_id' => $unitId,
                'property_id' => $unitId ? val("SELECT property_id FROM prpt_units WHERE id = ?", [$unitId]) : null, 'stage_id' => $stage, 'score' => 85,
                'notes' => 'Reserved "' . ($p['listing']['title'] ?? '') . '" on Vikao (' . ($p['package']['name'] ?? 'standard') . '). Paid ' . money($p['amount_paid']) . ' · ' . $p['mpesa_receipt'],
                'ecom_order_number' => $p['order_number'], 'next_follow_up_at' => date('Y-m-d H:i:s', strtotime('+1 day')), 'created_by' => staff_id()]);
            lead_log($leadId, 'note', 'Lead created from Vikao marketplace reservation ' . $p['order_number']);
            if ($unitId) set_unit_status((int)$unitId, 'reserved', 'Reserved on Vikao ' . $p['order_number']);
        }
        if ($o) eq("UPDATE orders SET pms_sync_status = 'acknowledged', pms_reference = ?, status = IF(status = 'paid', 'processing', status) WHERE id = ?", ['PMS-LEAD-' . ($leadId ?? 'x'), $o['id']]);
    }
    eq("UPDATE pms_events SET status = 'acknowledged', acknowledged_at = NOW() WHERE id = ?", [$eventId]);
    insert('ecm_sync_log', ['action' => 'event.ack', 'ecom_ref' => (string)$eventId, 'details' => json_encode(['type' => $ev['event_type'], 'lead_id' => $leadId]), 'user_id' => staff_id()]);
    return $leadId;
}

/**
 * Publish a PMS unit to the marketplace as an Official Vikao listing.
 * $d: title, description_html, price, reservation_fee, deal_type (sale|rent), category_slug, ecom_location_id, billing_period, photos (abs paths ≥6), packages[]
 */
function ecom_push_unit(int $unitId, array $d): string {
    $u = one("SELECT u.*, pr.name AS property_name, pr.ecom_location_id, pr.code AS prop_code FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.id = ?", [$unitId]);
    $official = eone("SELECT id FROM users WHERE account_type = 'official' ORDER BY id LIMIT 1");
    if (!$official) throw new RuntimeException('No official account exists in the marketplace.');
    $cat = eone("SELECT * FROM categories WHERE slug = ?", [$d['category_slug']]);
    if (!$cat) throw new ValidationError(['category_slug' => 'Choose a marketplace category.']);
    $locId = (int)($d['ecom_location_id'] ?: $u['ecom_location_id']);
    $chain = []; $id = $locId; while ($id && count($chain) < 5) { $r = eone("SELECT id, parent_id, country_id FROM locations WHERE id = ?", [$id]); if (!$r) break; array_unshift($chain, $r); $id = $r['parent_id']; }
    if (!$chain) throw new ValidationError(['ecom_location_id' => 'Pick the marketplace location (county / sub-county / area).']);
    if (count($d['photos'] ?? []) < 6) throw new ValidationError(['photos' => 'The marketplace requires at least 6 photos.']);
    $text = trim(html_entity_decode(strip_tags((string)$d['description_html'])));
    return tx(function () use ($u, $d, $official, $cat, $chain, $text) {
        $uuid = uuid4(); $ref = 'VK-' . random_code(6);
        eq("INSERT INTO listings (uuid, ref_code, user_id, category_id, family, deal_type, is_official, title, slug, description_html, description_text, country_id, location_id, region_id,
            estate, price, currency_code, price_unit, billing_period, reservation_fee, listed_by, status, is_premium, submitted_at, published_at, pms_property_id, pms_unit_id)
            VALUES (?,?,?,?,?,?,1,?,?,?,?,?,?,?,?,?, 'KES', 'entire', ?, ?, 'company', 'active', 1, NOW(), NOW(), ?, ?)", [
            $uuid, $ref, $official['id'], $cat['id'], $cat['family'], $cat['deal_type'], $d['title'], slugify($d['title']), sanitize_html((string)$d['description_html']), $text,
            $chain[0]['country_id'], end($chain)['id'], $chain[0]['id'], $u['property_name'], (float)$d['price'], $cat['deal_type'] === 'rent' ? ($d['billing_period'] ?? 'month') : 'once',
            (float)$d['reservation_fee'], $u['prop_code'], $u['unit_no']]);
        $lid = (int)ecom_db()->lastInsertId();
        if (in_array($cat['family'], ['residential', 'short_stay'], true)) {
            $pt = (int)eval_("SELECT id FROM property_types WHERE family = 'residential' AND name LIKE ? LIMIT 1", ['%' . ($u['unit_type'] === 'apartment' ? 'Apartment' : ucfirst((string)$u['unit_type'])) . '%']) ?: (int)eval_("SELECT id FROM property_types WHERE family = 'residential' LIMIT 1");
            eq("INSERT INTO listing_residential_details (listing_id, property_type_id, bedrooms, bathrooms, size_value, size_unit, size_sqm, self_contained, `condition`, furnishing) VALUES (?,?,?,?,?, 'sqm', ?, 1, 'new', ?)",
               [$lid, $pt, $u['bedrooms'], $u['bathrooms'], $u['size_sqm'], $u['size_sqm'], $u['furnishing'] ?: 'unfurnished']);
        } elseif ($cat['family'] === 'commercial') {
            $pt = (int)eval_("SELECT id FROM property_types WHERE family = 'commercial' AND name = 'Office'");
            eq("INSERT INTO listing_commercial_details (listing_id, property_type_id, size_value, size_unit, size_sqm, `condition`) VALUES (?,?,?,'sqm',?,'new')", [$lid, $pt, $u['size_sqm'] ?: 1, $u['size_sqm'] ?: 1]);
        }
        $dir = rtrim((string)config('ecom_db.uploads_dir'), '/') . '/' . date('Y/m');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        foreach (array_values($d['photos']) as $i => $abs) {
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION)) ?: 'jpg'; $name = $lid . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!@copy($abs, "$dir/$name")) throw new RuntimeException('Could not write photos to the marketplace uploads folder (check ecom_db.uploads_dir).');
            $rel = 'uploads/listings/' . date('Y/m') . '/' . $name;
            eq("INSERT INTO listing_media (listing_id, path, thumb_path, mime_type, size_bytes, sort_order) VALUES (?,?,?,?,?,?)", [$lid, $rel, $rel, mime_content_type("$dir/$name") ?: 'image/jpeg', filesize("$dir/$name"), $i]);
        }
        eq("UPDATE listings SET cover_media_id = (SELECT id FROM listing_media WHERE listing_id = ? ORDER BY sort_order LIMIT 1) WHERE id = ?", [$lid, $lid]);
        foreach (array_values($d['packages'] ?? []) as $i => $p) {
            if (empty($p['name']) || empty($p['total_price'])) continue;
            eq("INSERT INTO listing_packages (listing_id, name, description, package_type, total_price, deposit_amount, is_recommended, sort_order) VALUES (?,?,?,?,?,?,?,?)",
               [$lid, $p['name'], $p['description'] ?? null, $p['package_type'] ?? 'outright', (float)$p['total_price'], nullable($p['deposit_amount'] ?? null), (int)!empty($p['is_recommended']), $i]);
        }
        eq("INSERT INTO listing_reviews (listing_id, submission_type, decision, reviewer_source, decided_at) VALUES (?, 'new', 'approved', 'pms', NOW())", [$lid]);
        q("UPDATE prpt_units SET ecom_listing_uuid = ? WHERE id = ?", [$uuid, $u['id']]);
        insert('ecm_sync_log', ['action' => 'listing.push_unit', 'ecom_ref' => $uuid, 'unit_id' => $u['id'], 'details' => json_encode(['title' => $d['title'], 'ref' => $ref]), 'user_id' => staff_id()]);
        audit('ecommerce', 'push_unit', 'unit', $u['id'], 'Published unit ' . $u['unit_no'] . ' to the marketplace (' . $ref . ')');
        return $uuid;
    }, ecom_db());
}
