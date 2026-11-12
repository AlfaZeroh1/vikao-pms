<?php
/**
 * Safaricom Daraja (M-Pesa). Integration codes: mpesa_vikao (PMS paybill), mpesa_ecom (marketplace).
 * creds: consumer_key, consumer_secret, passkey · cfg: shortcode, callback_base
 * Endpoints for Safaricom: webhooks/mpesa.php?action=confirm|validate|stk
 */
function mpesa_base(array $i): string { return $i['environment'] === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke'; }
function mpesa_token(string $code = 'mpesa_vikao'): string {
    $i = integration($code);
    $r = http_request('GET', mpesa_base($i) . '/oauth/v1/generate?grant_type=client_credentials', [], null, $code, [$i['creds']['consumer_key'] ?? '', $i['creds']['consumer_secret'] ?? '']);
    if (!$r['ok'] || empty($r['json']['access_token'])) { integration_mark($code, false, 'Token: HTTP ' . $r['status']); throw new RuntimeException('M-Pesa authentication failed.'); }
    return $r['json']['access_token'];
}
/** Register C2B confirmation/validation URLs for the paybill */
function mpesa_register_urls(string $code = 'mpesa_vikao'): array {
    $i = integration($code); if (!integration_live($code)) return ['simulated' => true];
    $base = rtrim($i['cfg']['callback_base'] ?? config('app.pms_url'), '/');
    $r = http_request('POST', mpesa_base($i) . '/mpesa/c2b/v1/registerurl', ['Authorization: Bearer ' . mpesa_token($code)],
        ['ShortCode' => $i['cfg']['shortcode'], 'ResponseType' => 'Completed', 'ConfirmationURL' => "$base/webhooks/mpesa.php?action=confirm&i=$code", 'ValidationURL' => "$base/webhooks/mpesa.php?action=validate&i=$code"], $code);
    integration_mark($code, $r['ok'], $r['ok'] ? null : $r['body']);
    return $r['json'] ?? ['error' => $r['body']];
}
/** STK push (portal "Pay now"). Returns CheckoutRequestID. */
function mpesa_stk_push(string $phone, float $amount, string $accountRef, string $desc, string $code = 'mpesa_vikao'): array {
    $i = integration($code); $phone = ltrim((string)normalize_phone($phone), '+');
    if (!integration_live($code)) return ['simulated' => true, 'CheckoutRequestID' => 'ws_CO_SIM' . date('YmdHis') . random_int(100, 999), 'CustomerMessage' => 'Simulated STK push'];
    $ts = date('YmdHis'); $sc = $i['cfg']['shortcode'];
    $base = rtrim($i['cfg']['callback_base'] ?? config('app.pms_url'), '/');
    $r = http_request('POST', mpesa_base($i) . '/mpesa/stkpush/v1/processrequest', ['Authorization: Bearer ' . mpesa_token($code)], [
        'BusinessShortCode' => $sc, 'Password' => base64_encode($sc . ($i['creds']['passkey'] ?? '') . $ts), 'Timestamp' => $ts, 'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => (int)ceil($amount), 'PartyA' => $phone, 'PartyB' => $sc, 'PhoneNumber' => $phone, 'CallBackURL' => "$base/webhooks/mpesa.php?action=stk&i=$code",
        'AccountReference' => mb_substr($accountRef, 0, 12), 'TransactionDesc' => mb_substr($desc, 0, 13)], $code);
    integration_mark($code, $r['ok'], $r['ok'] ? null : $r['body']);
    if (!$r['ok']) throw new RuntimeException('M-Pesa request failed: ' . ($r['json']['errorMessage'] ?? 'HTTP ' . $r['status']));
    return $r['json'];
}
/** Map a C2B confirmation body into a gateway transaction and ingest it. */
function mpesa_handle_confirmation(array $b, string $code = 'mpesa_vikao'): array {
    $acc = val("SELECT id FROM fin_accounts WHERE integration_code = ? LIMIT 1", [$code]);
    $name = trim(($b['FirstName'] ?? '') . ' ' . ($b['MiddleName'] ?? '') . ' ' . ($b['LastName'] ?? ''));
    return ingest_gateway_txn('mpesa', ['transaction_id' => $b['TransID'], 'transaction_type' => $b['TransactionType'] ?? 'PayBill', 'amount' => (float)$b['TransAmount'],
        'payer_name' => $name ?: null, 'payer_phone' => isset($b['MSISDN']) && ctype_digit((string)$b['MSISDN']) ? '+' . $b['MSISDN'] : null, 'bill_ref' => $b['BillRefNumber'] ?? null,
        'transacted_at' => isset($b['TransTime']) ? date('Y-m-d H:i:s', strtotime((string)$b['TransTime'])) : date('Y-m-d H:i:s'), 'account_id' => $acc ? (int)$acc : null,
        'raw' => $b, 'simulated' => !empty($b['_simulated'])]);
}
/** Generate a realistic simulated paybill payment (for demos / testing the matching pipeline). */
function mpesa_simulate_c2b(float $amount, string $billRef, string $phone, string $name = 'JOHN DOE', string $code = 'mpesa_vikao'): array {
    [$f, $l] = array_pad(explode(' ', strtoupper($name), 2), 2, '');
    return mpesa_handle_confirmation(['TransactionType' => 'Pay Bill', 'TransID' => 'S' . strtoupper(random_code(9, 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789')), 'TransTime' => date('YmdHis'),
        'TransAmount' => $amount, 'BusinessShortCode' => integration($code)['cfg']['shortcode'] ?? '000000', 'BillRefNumber' => $billRef, 'MSISDN' => ltrim((string)normalize_phone($phone), '+'),
        'FirstName' => $f, 'LastName' => $l, '_simulated' => 1], $code);
}
