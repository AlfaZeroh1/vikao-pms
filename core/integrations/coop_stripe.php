<?php
/**
 * Co-operative Bank (Co-op Connect / IPN) and Stripe.
 * coop_vikao   creds: consumer_key, consumer_secret · cfg: account_number, ipn_secret
 * stripe_vikao creds: secret_key, webhook_secret     · cfg: publishable_key, currency
 */

/* ---------------- Co-op Bank ---------------- */
function coop_token(): string {
    $i = integration('coop_vikao');
    $r = http_request('POST', 'https://openapi.co-opbank.co.ke/token', ['Content-Type: application/x-www-form-urlencoded'], 'grant_type=client_credentials', 'coop_vikao', [$i['creds']['consumer_key'] ?? '', $i['creds']['consumer_secret'] ?? '']);
    if (!$r['ok'] || empty($r['json']['access_token'])) { integration_mark('coop_vikao', false, $r['body']); throw new RuntimeException('Co-op Bank authentication failed.'); }
    return $r['json']['access_token'];
}
/** Pull recent account transactions (credits only) and ingest them. */
function coop_pull_transactions(int $days = 2): array {
    if (!integration_live('coop_vikao')) return ['simulated' => true, 'ingested' => 0];
    $i = integration('coop_vikao');
    $r = http_request('POST', 'https://openapi.co-opbank.co.ke/Enquiry/AccountTransactions/1.0.0/', ['Authorization: Bearer ' . coop_token()],
        ['MessageReference' => 'VK' . date('YmdHis'), 'AccountNumber' => $i['cfg']['account_number'] ?? '', 'NoOfTransactions' => '100'], 'coop_vikao');
    $n = 0;
    foreach (($r['json']['Transactions'] ?? []) as $t) {
        if (($t['CreditAmount'] ?? 0) <= 0) continue;
        $res = coop_ingest(['TransactionId' => $t['TransactionId'] ?? $t['TransactionReference'] ?? md5(json_encode($t)), 'Amount' => $t['CreditAmount'], 'Narration' => $t['Narration'] ?? '',
            'TransactionDate' => $t['TransactionDate'] ?? date('Y-m-d H:i:s'), 'CustomerName' => $t['CustomerName'] ?? null] + $t);
        if (empty($res['duplicate'])) $n++;
    }
    integration_mark('coop_vikao', $r['ok'], $r['ok'] ? null : $r['body']);
    return ['ingested' => $n];
}
/** IPN / pulled transaction → gateway feed. The narration usually carries the account reference. */
function coop_ingest(array $t): array {
    $ref = $t['BillRef'] ?? null;
    if (!$ref && !empty($t['Narration']) && preg_match('/\b([A-Z]{0,4}-?[A-Z]?\d{1,4}[A-Z]?)\b/i', (string)$t['Narration'], $m)) $ref = $m[1];
    $acc = val("SELECT id FROM fin_accounts WHERE integration_code = 'coop_vikao' LIMIT 1");
    return ingest_gateway_txn('coop', ['transaction_id' => (string)$t['TransactionId'], 'transaction_type' => 'Bank credit', 'amount' => (float)$t['Amount'],
        'payer_name' => $t['CustomerName'] ?? null, 'payer_phone' => $t['CustomerMobileNumber'] ?? null, 'bill_ref' => $ref,
        'transacted_at' => date('Y-m-d H:i:s', strtotime((string)($t['TransactionDate'] ?? 'now'))), 'account_id' => $acc ? (int)$acc : null, 'raw' => $t, 'simulated' => !empty($t['_simulated'])]);
}

/* ---------------- Stripe ---------------- */
function stripe_api(string $method, string $path, array $params = [], string $code = 'stripe_vikao'): array {
    $i = integration($code);
    $r = http_request($method, 'https://api.stripe.com/v1/' . ltrim($path, '/'), ['Authorization: Bearer ' . ($i['creds']['secret_key'] ?? ''), 'Content-Type: application/x-www-form-urlencoded'],
        $params ? http_build_query($params) : null, $code);
    integration_mark($code, $r['ok'], $r['ok'] ? null : $r['body']);
    if (!$r['ok']) throw new RuntimeException('Stripe: ' . ($r['json']['error']['message'] ?? 'HTTP ' . $r['status']));
    return $r['json'];
}
/** Hosted checkout for a portal invoice payment (card). */
function stripe_checkout_for_invoice(array $inv, string $successUrl, string $cancelUrl, string $code = 'stripe_vikao'): array {
    if (!integration_live($code)) return ['simulated' => true, 'url' => $successUrl . (str_contains($successUrl, '?') ? '&' : '?') . 'simulated_stripe=1'];
    $cur = strtolower(integration($code)['cfg']['currency'] ?? 'kes');
    $s = stripe_api('POST', 'checkout/sessions', ['mode' => 'payment', 'success_url' => $successUrl, 'cancel_url' => $cancelUrl, 'client_reference_id' => $inv['uuid'],
        'line_items[0][quantity]' => 1, 'line_items[0][price_data][currency]' => $cur, 'line_items[0][price_data][unit_amount]' => (int)round((float)$inv['balance'] * 100),
        'line_items[0][price_data][product_data][name]' => 'Invoice ' . $inv['invoice_no'], 'metadata[invoice_uuid]' => $inv['uuid'], 'metadata[party_id]' => $inv['party_id'],
        'payment_intent_data[metadata][invoice_uuid]' => $inv['uuid']], $code);
    return ['url' => $s['url'], 'id' => $s['id']];
}
function stripe_verify_signature(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    $parts = []; foreach (explode(',', $header) as $kv) { [$k, $v] = array_pad(explode('=', $kv, 2), 2, ''); $parts[$k][] = $v; }
    $t = (int)($parts['t'][0] ?? 0); if (!$t || abs(time() - $t) > $tolerance) return false;
    $sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($parts['v1'] ?? [] as $v) if (hash_equals($sig, $v)) return true;
    return false;
}
/** checkout.session.completed / payment_intent.succeeded → payment + allocation to the invoice */
function stripe_handle_event(array $event): ?array {
    $obj = $event['data']['object'] ?? [];
    if (!in_array($event['type'] ?? '', ['checkout.session.completed', 'payment_intent.succeeded'], true)) return null;
    $amount = ($obj['amount_total'] ?? $obj['amount_received'] ?? 0) / 100;
    $invUuid = $obj['metadata']['invoice_uuid'] ?? $obj['client_reference_id'] ?? null;
    $inv = $invUuid ? one("SELECT * FROM bln_invoices WHERE uuid = ?", [$invUuid]) : null;
    $res = ingest_gateway_txn('stripe', ['transaction_id' => $obj['payment_intent'] ?? $obj['id'], 'transaction_type' => $event['type'], 'amount' => $amount,
        'payer_name' => $obj['customer_details']['name'] ?? null, 'bill_ref' => $inv['invoice_no'] ?? null, 'raw' => $event,
        'account_id' => (int)val("SELECT id FROM fin_accounts WHERE integration_code = 'stripe_vikao' LIMIT 1") ?: null]);
    if (!$res['duplicate'] && $inv && empty($res['payment']['party_id'])) {
        update('fin_payments', ['party_id' => $inv['party_id'], 'unit_id' => $inv['unit_id'], 'lease_id' => $inv['lease_id'], 'property_id' => $inv['property_id']], 'id = :id', ['id' => $res['payment']['id']]);
        payment_auto_allocate((int)$res['payment']['id']);
    }
    return $res;
}
