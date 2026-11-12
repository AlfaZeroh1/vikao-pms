<?php
/** Staff authentication, RBAC and live-session tracking (who's online & where). */

function staff(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    if (VK_APP === 'portal' || empty($_SESSION['staff_id'])) return $u = null;
    $u = one("SELECT u.*, r.code AS role_code, r.name AS role_name, r.scope_all_properties FROM sys_users u JOIN sys_roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.status = 'active' AND u.deleted_at IS NULL", [$_SESSION['staff_id']]);
    if (!$u) { unset($_SESSION['staff_id']); return $u = null; }
    return $u;
}
function staff_id(): ?int { return ($u = staff()) ? (int)$u['id'] : null; }
function staff_name(?array $u = null): string { $u = $u ?? staff(); return $u ? trim($u['first_name'] . ' ' . $u['last_name']) : 'System'; }

function permissions(): array {
    static $p = null;
    if ($p !== null) return $p;
    $u = staff(); if (!$u) return $p = [];
    if ($u['role_code'] === 'super_admin') return $p = ['*' => true];
    return $p = array_flip(col("SELECT p.code FROM sys_role_permissions rp JOIN sys_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?", [$u['role_id']]));
}
/** can('billing.invoices.edit') — also accepts a module prefix: can('billing') */
function can(string $perm): bool {
    $p = permissions(); if (isset($p['*']) || isset($p[$perm])) return true;
    if (!str_contains($perm, '.') || substr_count($perm, '.') === 1) foreach ($p as $k => $_) if (str_starts_with($k, $perm . '.')) return true;
    return false;
}
function require_staff(): array {
    $u = staff(); if ($u) return $u;
    if (is_ajax() || str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api')) json_fail('Your session has ended. Please sign in again.', 401);
    $_SESSION['intended'] = $_SERVER['REQUEST_URI'] ?? ''; redirect(url('admin/login.php'));
}
function require_perm(string $perm): void {
    require_staff();
    if (can($perm)) return;
    if (is_ajax() || str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api')) json_fail("You don't have permission to do that ($perm).", 403);
    http_response_code(403); $GLOBALS['__forbidden'] = $perm;
}

/** Properties the user may access (null = all) */
function scoped_property_ids(): ?array {
    $u = staff(); if (!$u || $u['scope_all_properties'] || $u['role_code'] === 'super_admin') return null;
    static $ids = null; return $ids ??= array_map('intval', col("SELECT property_id FROM sys_user_properties WHERE user_id = ?", [$u['id']]));
}
/** SQL fragment restricting a property column to the user's scope */
function scope_sql(string $col = 'property_id'): string { $ids = scoped_property_ids(); return $ids === null ? '1=1' : "$col IN (" . in_ints($ids) . ")"; }

function staff_login(string $login, string $password): ?array {
    $u = one("SELECT * FROM sys_users WHERE (email = ? OR username = ?) AND deleted_at IS NULL", [strtolower(trim($login)), strtolower(trim($login))]);
    if (!$u || !password_verify($password, $u['password_hash']) || $u['status'] !== 'active') return null;
    session_regenerate_id(true);
    $_SESSION['staff_id'] = (int)$u['id'];
    $_SESSION['sess_key'] = bin2hex(random_bytes(32));
    insert('sys_sessions', ['session_key' => $_SESSION['sess_key'], 'user_id' => $u['id'], 'ip_address' => client_ip(), 'user_agent' => user_agent()]);
    q("UPDATE sys_users SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = ?", [$u['id']]);
    audit('admin', 'login', 'user', $u['id'], 'Signed in');
    return $u;
}
function staff_logout(): void {
    if (!empty($_SESSION['sess_key'])) q("UPDATE sys_sessions SET ended_at = NOW() WHERE session_key = ?", [$_SESSION['sess_key']]);
    audit('admin', 'logout', 'user', staff_id(), 'Signed out');
    unset($_SESSION['staff_id'], $_SESSION['sess_key']); session_regenerate_id(true);
}
/** Heartbeat: what page is the user on right now */
function track_activity(string $path, string $title): void {
    $u = staff(); if (!$u || empty($_SESSION['sess_key'])) return;
    q("UPDATE sys_sessions SET last_activity = NOW(), current_path = ?, current_title = ? WHERE session_key = ?", [mb_substr($path, 0, 255), mb_substr($title, 0, 160), $_SESSION['sess_key']]);
    q("UPDATE sys_users SET last_seen_at = NOW() WHERE id = ?", [$u['id']]);
}
function staff_notify(int $userId, string $type, string $title, ?string $body = null, ?string $icon = null, ?string $link = null): void {
    insert('sys_notifications', ['user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'icon' => $icon, 'link_url' => $link]);
}
function staff_notify_perm(string $perm, string $type, string $title, ?string $body = null, ?string $icon = null, ?string $link = null): void {
    $ids = col("SELECT DISTINCT u.id FROM sys_users u JOIN sys_roles r ON r.id = u.role_id LEFT JOIN sys_role_permissions rp ON rp.role_id = r.id LEFT JOIN sys_permissions p ON p.id = rp.permission_id
               WHERE u.status = 'active' AND (r.code = 'super_admin' OR p.code = ?)", [$perm]);
    foreach ($ids as $id) staff_notify((int)$id, $type, $title, $body, $icon, $link);
}
