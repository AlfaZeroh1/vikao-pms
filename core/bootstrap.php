<?php
/**
 * Shared core for the PMS and the self-service portal (the portal repo carries an identical copy of /core).
 * Define VK_APP = 'admin' | 'portal' | 'cli' before requiring.
 */
declare(strict_types=1);
define('VK_ROOT', dirname(__DIR__));
defined('VK_APP') || define('VK_APP', PHP_SAPI === 'cli' ? 'cli' : 'admin');

$__cfg = is_file(VK_ROOT . '/config/config.php') ? VK_ROOT . '/config/config.php' : VK_ROOT . '/config/config.sample.php';
$GLOBALS['__config'] = require $__cfg;

function config(string $key, $default = null) {
    $v = $GLOBALS['__config'];
    foreach (explode('.', $key) as $p) { if (!is_array($v) || !array_key_exists($p, $v)) return $default; $v = $v[$p]; }
    return $v;
}
date_default_timezone_set(config('app.timezone', 'Africa/Nairobi'));
mb_internal_encoding('UTF-8');
ini_set('display_errors', config('app.env') === 'production' ? '0' : '1');
error_reporting(E_ALL);

if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_name(VK_APP === 'portal' ? 'vikao_portal' : 'vikao_pms');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}

foreach (['db', 'helpers', 'settings', 'audit', 'sequence', 'auth_staff', 'auth_portal', 'files'] as $f) require VK_ROOT . "/core/$f.php";
foreach (glob(VK_ROOT . '/core/engines/*.php') as $f) require $f;
foreach (glob(VK_ROOT . '/core/integrations/*.php') as $f) require $f;
