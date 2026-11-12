<?php
/**
 * Vikao PMS installer (CLI):  php database/install.php [--fresh] [--no-demo]
 * Creates the schema, reference data and (optionally) a demo portfolio.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run from the command line.'); }
define('VK_APP', 'cli');
require __DIR__ . '/../core/bootstrap.php';
$args = $argv ?? [];
$c = config('db');
$pdo = new PDO("mysql:host={$c['host']};port={$c['port']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (in_array('--fresh', $args, true)) { $pdo->exec("DROP DATABASE IF EXISTS `{$c['name']}`"); echo "• dropped {$c['name']}\n"; }
$sql = file_get_contents(__DIR__ . '/schema.sql');
$sql = str_replace(['CREATE DATABASE IF NOT EXISTS vikao_pms', 'USE vikao_pms;'], ["CREATE DATABASE IF NOT EXISTS `{$c['name']}`", "USE `{$c['name']}`;"], $sql);
$sql = preg_replace('/^--.*$/m', '', $sql); $n = 0;
foreach (preg_split('/;\s*\n/', $sql) as $stmt) { if (trim($stmt) === '') continue; $pdo->exec($stmt); $n++; }
echo "• schema: $n statements\n";
require __DIR__ . '/seed.php';
echo "• reference data seeded\n";
if (!in_array('--no-demo', $args, true)) { require __DIR__ . '/demo_seed.php'; }
else {
    $rid = (int)val("SELECT id FROM sys_roles WHERE code = 'super_admin'");
    insert('sys_users', ['uuid' => uuid4(), 'role_id' => $rid, 'first_name' => 'System', 'last_name' => 'Admin', 'email' => 'admin@vikao.io', 'username' => 'admin', 'password_hash' => password_hash('Admin@2026', PASSWORD_DEFAULT), 'must_change_password' => 1]);
    echo "• admin login: admin / Admin@2026 (change it on first login)\n";
}
echo "✔ Vikao PMS database ready.\n";
