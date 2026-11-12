<?php
/** Vikao PMS — front controller: ?r=module/page → modules/<module>/<page>.php */
define('VK_APP', 'admin');
require __DIR__ . '/core/bootstrap.php';
require __DIR__ . '/layout/menu.php';
require __DIR__ . '/layout/ui.php';
require __DIR__ . '/layout/crud.php';

$me = require_staff();
if ($me['must_change_password'] && ($_GET['r'] ?? '') !== 'dashboard/profile') { flash('info', 'Please set a new password to continue.'); redirect(page('dashboard/profile', ['force' => 1])); }

$route = preg_replace('#[^a-z0-9/_-]#', '', strtolower((string)($_GET['r'] ?? 'dashboard/index')));
if (!str_contains($route, '/')) $route .= '/index';
$file = __DIR__ . '/modules/' . $route . '.php';
$meta = route_meta($route);

$title = $meta['label'] ?? 'Vikao PMS'; $subtitle = null; $actions = ''; $layout = 'app'; $crumbs = [];
if (!is_file($file) || !$meta) { http_response_code(404); $content = ui_empty('fa-map-signs', 'Page not found', 'The page you asked for does not exist.'); $title = 'Not found'; }
elseif (!can($meta['perm'])) { http_response_code(403); $content = ui_empty('fa-lock', 'Access denied', 'Your role does not include “' . $meta['perm'] . '”. Ask an administrator.'); $title = 'Access denied'; }
else {
    ob_start();
    try { include $file; }
    catch (ValidationError $ex) { echo '<div class="vk-alert-error">' . e(implode(' ', $ex->errors)) . '</div>'; }
    catch (RuntimeException $ex) { if ($ex instanceof PDOException) throw $ex; echo '<div class="vk-alert-error">' . e($ex->getMessage()) . '</div>'; }
    $content = ob_get_clean();
}
track_activity($_SERVER['REQUEST_URI'] ?? $route, $title);
if ($layout === 'print') { include __DIR__ . '/layout/print.php'; exit; }
include __DIR__ . '/layout/shell.php';
