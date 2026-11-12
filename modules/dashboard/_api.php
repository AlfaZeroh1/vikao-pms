<?php
return [
    'theme' => [null, function () {
        $t = (string)input('theme'); if (!in_array($t, ['system', 'light', 'dark'], true)) json_fail('Invalid theme.');
        q("UPDATE sys_users SET theme = ? WHERE id = ?", [$t, staff_id()]); json_ok();
    }],
    'counts' => [null, function () {
        json_ok(['notifications' => (int)val("SELECT COUNT(*) FROM sys_notifications WHERE user_id = ? AND read_at IS NULL", [staff_id()]),
                 'online' => (int)val("SELECT COUNT(DISTINCT user_id) FROM sys_sessions WHERE ended_at IS NULL AND last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")]);
    }],
    'read-notifications' => [null, function () { q("UPDATE sys_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL", [staff_id()]); json_ok([], 'All caught up'); }],
    'profile' => [null, function () {
        $d = validate($_POST, ['first_name' => 'required|min:2', 'email' => 'required|email']);
        if (val("SELECT 1 FROM sys_users WHERE email = ? AND id <> ?", [strtolower($d['email']), staff_id()])) throw new ValidationError(['email' => 'Email already in use.']);
        update('sys_users', ['first_name' => $d['first_name'], 'last_name' => nullable(input('last_name')), 'email' => strtolower($d['email']), 'phone' => nullable(input('phone')), 'job_title' => nullable(input('job_title'))], 'id = :id', ['id' => staff_id()]);
        audit('admin', 'update', 'user', staff_id(), 'Updated own profile');
        json_ok([], 'Profile saved.');
    }],
    'password' => [null, function () {
        $u = staff(); $new = (string)($_POST['new_password'] ?? '');
        if (!$u['must_change_password'] && !password_verify((string)($_POST['current_password'] ?? ''), $u['password_hash'])) throw new ValidationError(['current_password' => 'Current password is wrong.']);
        if (strlen($new) < 8 || !preg_match('/\d/', $new) || !preg_match('/[A-Za-z]/', $new)) throw new ValidationError(['new_password' => 'Use 8+ characters with letters and numbers.']);
        if ($new !== ($_POST['confirm_password'] ?? '')) throw new ValidationError(['confirm_password' => 'Passwords do not match.']);
        update('sys_users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0], 'id = :id', ['id' => $u['id']]);
        audit('admin', 'password', 'user', $u['id'], 'Changed password');
        json_ok(['redirect' => page('dashboard/index')], 'Password updated.');
    }],
];
