<?php
/** Public iCal export for one short-stay listing (give the URL to Airbnb / Booking.com). */
define('VK_APP', 'cli');
require dirname(__DIR__) . '/core/bootstrap.php';
$l = one("SELECT id FROM sst_listings WHERE ical_export_token = ?", [preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''))]);
if (!$l) { http_response_code(404); exit('Not found'); }
header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: inline; filename="vikao-' . $l['id'] . '.ics"');
echo sst_ical((int)$l['id']);
