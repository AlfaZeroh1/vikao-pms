<?php
/** Administration actions */
return [
    'save-user' => ['admin.users.manage', function () {
        $d = validate($_POST, ['first_name' => 'required', 'email' => 'required|email', 'role_id' => 'required']);
        $id = input_int('id'); $email = strtolower(trim((string)$d['email'])); $username = trim((string)input('username')) ?: null;
        if (val("SELECT 1 FROM sys_users WHERE email = ? AND id <> ? AND deleted_at IS NULL", [$email, $id ?? 0])) throw new ValidationError(['email' => 'Email already used by another user.']);
        if ($username && val("SELECT 1 FROM sys_users WHERE username = ? AND id <> ? AND deleted_at IS NULL", [$username, $id ?? 0])) throw new ValidationError(['username' => 'Username taken.']);
        $role = one("SELECT * FROM sys_roles WHERE id = ?", [$d['role_id']]);
        if ($role['code'] === 'super_admin' && staff()['role_code'] !== 'super_admin') json_fail('Only a super admin can create super admins.', 403);
        $row = ['first_name' => $d['first_name'], 'last_name' => nullable(input('last_name')), 'email' => $email, 'username' => $username, 'phone' => normalize_phone(input('phone')) ?? nullable(input('phone')), 'job_title' => nullable(input('job_title')), 'role_id' => (int)$d['role_id'], 'status' => input('status', 'active')];
        $pw = (string)input('password', '');
        if ($id) {
            if ($id === staff_id() && $row['status'] !== 'active') json_fail('You cannot suspend yourself.');
            $old = one("SELECT * FROM sys_users WHERE id = ?", [$id]);
            if ($pw !== '') { if (strlen($pw) < 8) throw new ValidationError(['password' => 'At least 8 characters.']); $row['password_hash'] = password_hash($pw, PASSWORD_DEFAULT); $row['must_change_password'] = 1; }
            update('sys_users', $row, 'id = :id', ['id' => $id]);
            audit('admin', 'update', 'user', $id, 'Updated user ' . $email, array_diff_key($old, ['password_hash' => 1]), array_diff_key($row, ['password_hash' => 1]));
            if ($row['status'] !== 'active') q("UPDATE sys_sessions SET ended_at = NOW() WHERE user_id = ? AND ended_at IS NULL", [$id]);
        } else {
            if (strlen($pw) < 8) $pw = random_code(10, 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789');
            $id = insert('sys_users', $row + ['uuid' => uuid4(), 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1]);
            audit('admin', 'create', 'user', $id, 'Created user ' . $email . ' (' . $role['name'] . ')');
            if (input('send_welcome')) send_message('email', $email, 'Your Vikao PMS account', email_wrap('Welcome to Vikao PMS', '<p>Hi ' . e($row['first_name']) . ', an account was created for you as <b>' . e($role['name']) . '</b>.</p><p>Sign in at <a href="' . e(rtrim((string)config('app.pms_url'), '/') . '/login.php') . '">' . e(rtrim((string)config('app.pms_url'), '/')) . '</a><br>Login: <b>' . e($username ?: $email) . '</b><br>Temporary password: <b>' . e($pw) . '</b></p><p>You will be asked to set your own password.</p>'), ['user_id' => $id]);
        }
        q("DELETE FROM sys_user_properties WHERE user_id = ?", [$id]);
        if (!$role['scope_all_properties']) foreach (array_unique(array_map('intval', input_array('property_ids'))) as $p) insert('sys_user_properties', ['user_id' => $id, 'property_id' => $p]);
        json_ok(['id' => $id, 'temp_password' => !input('id') ? $pw : null], input('id') ? 'User updated.' : 'User created' . (input('send_welcome') ? ' and emailed their login.' : ' — temporary password: ' . $pw));
    }],
    'delete-user' => ['admin.users.manage', function () {
        $id = (int)input_int('id'); if ($id === staff_id()) json_fail('You cannot delete yourself.');
        q("UPDATE sys_users SET deleted_at = NOW(), status = 'suspended' WHERE id = ?", [$id]); q("UPDATE sys_sessions SET ended_at = NOW() WHERE user_id = ? AND ended_at IS NULL", [$id]);
        audit('admin', 'delete', 'user', $id, 'Removed user'); json_ok(['redirect' => page('admin/users')], 'User removed.');
    }],
    'kick' => ['admin.users.manage', function () { q("UPDATE sys_sessions SET ended_at = NOW() WHERE id = ?", [input_int('id')]); audit('admin', 'kick', 'session', input_int('id'), 'Ended a user session'); json_ok([], 'Session ended — they will be signed out on their next click.'); }],
    'save-role' => ['admin.roles.manage', function () {
        $d = validate($_POST, ['name' => 'required']); $id = input_int('id');
        $row = ['name' => $d['name'], 'description' => nullable(input('description')), 'scope_all_properties' => (int)!!input('scope_all_properties')];
        if ($id) { $r = one("SELECT * FROM sys_roles WHERE id = ?", [$id]); if ($r['code'] === 'super_admin') json_fail('The super admin role cannot be changed.'); update('sys_roles', $row, 'id = :id', ['id' => $id]); }
        else $id = insert('sys_roles', $row + ['code' => slugify(input('code') ?: $d['name']), 'is_system' => 0]);
        audit('admin', 'save', 'role', $id, 'Saved role ' . $row['name']);
        json_ok(['redirect' => page('admin/roles', ['id' => $id])], 'Role saved.');
    }],
    'role-permissions' => ['admin.roles.manage', function () {
        $id = (int)input_int('role_id'); $r = one("SELECT * FROM sys_roles WHERE id = ?", [$id]); if (!$r || $r['code'] === 'super_admin') json_fail('This role always has every permission.');
        $old = col("SELECT p.code FROM sys_role_permissions rp JOIN sys_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?", [$id]);
        $ids = array_map('intval', input_array('perms'));
        tx(function () use ($id, $ids) { q("DELETE FROM sys_role_permissions WHERE role_id = ?", [$id]); foreach ($ids as $p) insert('sys_role_permissions', ['role_id' => $id, 'permission_id' => $p]); });
        $new = col("SELECT code FROM sys_permissions WHERE id IN (" . (in_ints($ids) ?: '0') . ")");
        audit('admin', 'permissions', 'role', $id, 'Permissions for ' . $r['name'] . ': +' . count(array_diff($new, $old)) . ' / −' . count(array_diff($old, $new)), ['permissions' => $old], ['permissions' => $new]);
        json_ok([], 'Permissions saved (' . count($ids) . ').');
    }],
    'delete-role' => ['admin.roles.manage', function () {
        $r = one("SELECT * FROM sys_roles WHERE id = ?", [input_int('id')]); if ($r['is_system']) json_fail('Built-in roles cannot be deleted.');
        if (val("SELECT 1 FROM sys_users WHERE role_id = ? AND deleted_at IS NULL", [$r['id']])) json_fail('Move the users on this role first.');
        q("DELETE FROM sys_role_permissions WHERE role_id = ?", [$r['id']]); q("DELETE FROM sys_roles WHERE id = ?", [$r['id']]);
        audit('admin', 'delete', 'role', $r['id'], 'Deleted role ' . $r['name']); json_ok(['redirect' => page('admin/roles')], 'Role deleted.');
    }],
    'audit-entry' => ['admin.audit.view', function () { json_ok(['a' => one("SELECT * FROM sys_audit_log WHERE id = ?", [input_int('id')])]); }],
];
