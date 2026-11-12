<?php
/** Self-service portal accounts (owners & tenants) — one account per party. */

function portal_party(): ?array {
    static $p = false;
    if ($p !== false) return $p;
    if (VK_APP !== 'portal' || empty($_SESSION['portal_account_id'])) return $p = null;
    $p = one("SELECT pt.*, a.id AS account_id, a.username, a.theme AS portal_theme, a.app_installed_at FROM prt_accounts a JOIN prpt_parties pt ON pt.id = a.party_id
              WHERE a.id = ? AND a.status = 'active' AND pt.deleted_at IS NULL", [$_SESSION['portal_account_id']]);
    if (!$p) { unset($_SESSION['portal_account_id']); return $p = null; }
    if (empty($_SESSION['portal_seen']) || $_SESSION['portal_seen'] < time() - 120) { q("UPDATE prt_accounts SET last_seen_at = NOW() WHERE id = ?", [$p['account_id']]); $_SESSION['portal_seen'] = time(); }
    return $p;
}
function require_portal(): array {
    $p = portal_party(); if ($p) return $p;
    if (is_ajax()) json_fail('Please sign in again.', 401);
    redirect(url('login.php'));
}

/** Create (or fetch) the pending portal account for a party. */
function portal_ensure_account(int $partyId): array {
    $a = one("SELECT * FROM prt_accounts WHERE party_id = ?", [$partyId]);
    if ($a) return $a;
    insert('prt_accounts', ['party_id' => $partyId]);
    return one("SELECT * FROM prt_accounts WHERE party_id = ?", [$partyId]);
}

/** Issue an activation link (valid 7 days) and email it. Returns the link. */
function portal_send_activation(int $partyId): string {
    $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$partyId]);
    if (!$party || !$party['email']) throw new RuntimeException('This person has no email address on file.');
    $acc = portal_ensure_account($partyId);
    if ($acc['status'] === 'active') throw new RuntimeException('This portal account is already active.');
    $token = bin2hex(random_bytes(24));
    update('prt_accounts', ['activation_token_hash' => hash('sha256', $token), 'activation_expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')), 'activation_sent_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $acc['id']]);
    $link = rtrim((string)config('app.portal_url'), '/') . '/activate.php?token=' . $token;
    send_templated('portal_activation', $party, ['activation_link' => $link], 'email', 'party', $partyId);
    audit('property', 'portal_invite', 'party', $partyId, 'Portal activation link sent to ' . $party['email']);
    return $link;
}
function portal_find_by_token(string $token): ?array {
    return one("SELECT a.*, p.display_name, p.first_name, p.email FROM prt_accounts a JOIN prpt_parties p ON p.id = a.party_id
                WHERE a.activation_token_hash = ? AND a.activation_expires_at > NOW() AND a.status = 'pending'", [hash('sha256', $token)]);
}
function portal_login(string $login, string $password): ?array {
    $a = one("SELECT a.* FROM prt_accounts a JOIN prpt_parties p ON p.id = a.party_id WHERE (a.username = ? OR p.email = ?) AND a.status = 'active'", [strtolower(trim($login)), strtolower(trim($login))]);
    if (!$a || !$a['password_hash'] || !password_verify($password, $a['password_hash'])) return null;
    session_regenerate_id(true);
    $_SESSION['portal_account_id'] = (int)$a['id'];
    q("UPDATE prt_accounts SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = ?", [$a['id']]);
    return $a;
}
function portal_notify(int $partyId, string $type, string $title, ?string $body = null, ?string $icon = null, ?string $link = null): void {
    insert('prt_notifications', ['party_id' => $partyId, 'type' => $type, 'title' => $title, 'body' => $body, 'icon' => $icon, 'link_url' => $link]);
}
