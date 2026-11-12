<?php
/** Serves uploaded files to signed-in staff (uploads/ is not web-browsable). */
define('VK_APP', 'admin');
require __DIR__ . '/core/bootstrap.php';
require_staff();
$rel = (string)($_GET['f'] ?? '');
if (!preg_match('#^[a-z]+/\d{4}/\d{2}/[a-f0-9]+\.[a-z0-9]+$#', $rel)) { http_response_code(404); exit('Not found'); }
$abs = upload_abs($rel);
if (!is_file($abs)) { http_response_code(404); exit('Not found'); }
header('Content-Type: ' . (mime_content_type($abs) ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($abs)); header('Cache-Control: private, max-age=86400'); header('X-Content-Type-Options: nosniff');
readfile($abs);
