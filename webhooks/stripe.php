<?php
/** Stripe webhook: verifies the signature, then checkout.session.completed / payment_intent.succeeded → payment → consume. */
require __DIR__ . '/_boot.php';
$code = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['i'] ?? 'stripe_vikao'));
$body = hook_body(); log_inbound($code, 'stripe/webhook', $body);
$i = integration($code);
$secret = (string)($i['creds']['webhook_secret'] ?? '');
if ($secret !== '' && !stripe_verify_signature($body, (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret)) hook_reply(['error' => 'bad signature'], 400);
if ($secret === '' && !$i['simulate']) hook_reply(['error' => 'webhook secret not configured'], 400);
try { $r = stripe_handle_event(json_decode($body, true) ?: []); integration_mark($code, true); hook_reply(['received' => true, 'handled' => (bool)$r]); }
catch (Throwable $e) { error_log('[stripe hook] ' . $e); hook_reply(['error' => 'processing failed'], 500); }
