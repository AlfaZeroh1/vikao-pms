<?php
/** AJAX controller: api.php?r=module/action → modules/<module>/_api.php action closures */
define('VK_APP', 'admin');
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/layout/menu.php';
require __DIR__ . '/layout/ui.php';
require __DIR__ . '/layout/crud.php';

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ValidationError) json_fail($e->getMessage(), 422, $e->errors);
    if ($e instanceof RuntimeException && !($e instanceof PDOException)) json_fail($e->getMessage(), 400);
    error_log('[pms api] ' . $e);
    json_fail(config('app.env') === 'production' ? 'Server error — please try again.' : $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), 500);
});
require_staff();
if (is_post()) verify_csrf();

$r = preg_replace('#[^a-z0-9/_-]#', '', strtolower((string)($_GET['r'] ?? '')));
[$module, $action] = array_pad(explode('/', $r, 2), 2, '');
$file = __DIR__ . '/modules/' . $module . '/_api.php';
if (!$module || !is_file($file)) json_fail('Unknown endpoint.', 404);
$ACTIONS = require $file;
if (!isset($ACTIONS[$action])) json_fail('Unknown action.', 404);
[$perm, $fn] = $ACTIONS[$action];
if ($perm && !can($perm)) json_fail("You don't have permission to do that ($perm).", 403);
$fn();
json_ok();
