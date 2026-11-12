<?php
/**
 * Safaricom Daraja callbacks.
 *  ?action=validate&i=mpesa_vikao  → C2B validation (accept everything; matching happens on confirmation)
 *  ?action=confirm&i=mpesa_vikao   → C2B confirmation → payment → match → auto-consume
 *  ?action=stk&i=mpesa_vikao       → STK push result (the paybill confirmation also arrives; we only log here)
 * Optionally restrict by source IP (Safaricom ranges) at the web-server level.
 */
require __DIR__ . '/_boot.php';
$code = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['i'] ?? 'mpesa_vikao'));
$action = (string)($_GET['action'] ?? '');
$body = hook_body(); $b = json_decode($body, true) ?: [];
log_inbound($code, 'mpesa/' . $action, $body);
try {
    if ($action === 'validate') hook_reply(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    if ($action === 'confirm') {
        if (empty($b['TransID']) || !isset($b['TransAmount'])) hook_reply(['ResultCode' => 'C2B00016', 'ResultDesc' => 'Rejected'], 400);
        $r = mpesa_handle_confirmation($b, $code);
        integration_mark($code, true);
        hook_reply(['ResultCode' => 0, 'ResultDesc' => !empty($r['duplicate']) ? 'Duplicate ignored' : 'Accepted']);
    }
    if ($action === 'stk') {
        $cb = $b['Body']['stkCallback'] ?? [];
        if ((int)($cb['ResultCode'] ?? 1) === 0) {
            $items = []; foreach ($cb['CallbackMetadata']['Item'] ?? [] as $it) $items[$it['Name']] = $it['Value'] ?? null;
            // Paybill STK payments also produce a C2B confirmation; ingest here too (idempotent on receipt number) in case C2B URLs are not registered.
            if (!empty($items['MpesaReceiptNumber'])) ingest_gateway_txn('mpesa', ['transaction_id' => (string)$items['MpesaReceiptNumber'], 'transaction_type' => 'STK', 'amount' => (float)($items['Amount'] ?? 0),
                'payer_phone' => !empty($items['PhoneNumber']) ? '+' . $items['PhoneNumber'] : null, 'bill_ref' => $_GET['ref'] ?? null, 'transacted_at' => isset($items['TransactionDate']) ? date('Y-m-d H:i:s', strtotime((string)$items['TransactionDate'])) : date('Y-m-d H:i:s'), 'raw' => $b]);
        }
        hook_reply(['ResultCode' => 0, 'ResultDesc' => 'Received']);
    }
    hook_reply(['error' => 'unknown action'], 404);
} catch (Throwable $e) { error_log('[mpesa hook] ' . $e); integration_mark($code, false, $e->getMessage()); hook_reply(['ResultCode' => 0, 'ResultDesc' => 'Accepted (queued)']); }
