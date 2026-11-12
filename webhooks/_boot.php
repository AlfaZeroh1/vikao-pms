<?php
/** Public webhook bootstrap: no session, JSON in / JSON out, every call logged in api_logs. */
define('VK_APP', 'cli');
require dirname(__DIR__) . '/core/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
function hook_body(): string { static $b = null; return $b ??= (string)file_get_contents('php://input'); }
function hook_reply(array $d, int $status = 200): never { http_response_code($status); echo json_encode($d); exit; }
