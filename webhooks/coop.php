<?php
/** Co-operative Bank IPN (instant payment notification). Configure the shared secret as ?k= in the URL you give the bank. */
require __DIR__ . '/_boot.php';
$body = hook_body(); $t = json_decode($body, true) ?: [];
log_inbound('coop_vikao', 'coop/ipn', $body);
$i = integration('coop_vikao');
if (!empty($i['cfg']['ipn_key']) && !hash_equals((string)$i['cfg']['ipn_key'], (string)($_GET['k'] ?? ''))) hook_reply(['MessageCode' => '-1', 'Message' => 'Unauthorised'], 401);
try {
    $rows = isset($t['TransactionId']) ? [$t] : ($t['Transactions'] ?? []);
    $n = 0; foreach ($rows as $row) if ((float)($row['Amount'] ?? 0) > 0 && !empty($row['TransactionId'])) { coop_ingest($row); $n++; }
    integration_mark('coop_vikao', true);
    hook_reply(['MessageCode' => '0', 'Message' => "Received $n"]);
} catch (Throwable $e) { error_log('[coop hook] ' . $e); hook_reply(['MessageCode' => '-1', 'Message' => 'Error'], 500); }
