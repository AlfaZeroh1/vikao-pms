<?php
/**
 * Email (SMTP), SMS (Africa's Talking) and LLM (Anthropic) providers.
 * smtp       creds: password            · cfg: host, port, encryption (tls|ssl|none), username, from_email, from_name
 * sms_at     creds: api_key             · cfg: username, sender_id
 * anthropic  creds: api_key             · cfg: model
 */

/** Returns ['status' => sent|simulated, 'ref' => ...] or throws */
function mailer_send(string $to, string $subject, string $html): array {
    $i = integration('smtp');
    if (!$i || !$i['is_active'] || $i['simulate']) return ['status' => 'simulated', 'ref' => null];
    $c = $i['cfg']; $host = $c['host']; $port = (int)($c['port'] ?? 587); $enc = $c['encryption'] ?? 'tls';
    $fp = @stream_socket_client(($enc === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15);
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr");
    $read = function () use ($fp) { $d = ''; while (($l = fgets($fp, 515)) !== false) { $d .= $l; if (isset($l[3]) && $l[3] === ' ') break; } return $d; };
    $cmd = function (string $c, array $ok) use ($fp, $read) { fwrite($fp, $c . "\r\n"); $r = $read(); if (!in_array((int)substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP: ' . trim($r)); return $r; };
    $read(); $cmd('EHLO vikao.io', [250]);
    if ($enc === 'tls') { $cmd('STARTTLS', [220]); stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd('EHLO vikao.io', [250]); }
    if (!empty($c['username'])) { $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($c['username']), [334]); $cmd(base64_encode($i['creds']['password'] ?? ''), [235]); }
    $from = $c['from_email'] ?? $c['username']; $cmd("MAIL FROM:<$from>", [250]); $cmd("RCPT TO:<$to>", [250, 251]); $cmd('DATA', [354]);
    $msgId = bin2hex(random_bytes(8)) . '@vikao.io';
    $headers = "From: " . ($c['from_name'] ?? 'Vikao') . " <$from>\r\nTo: <$to>\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nMessage-ID: <$msgId>\r\nDate: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    $cmd($headers . "\r\n" . chunk_split(base64_encode($html)) . "\r\n.", [250]); $cmd('QUIT', [221]); fclose($fp);
    integration_mark('smtp', true);
    return ['status' => 'sent', 'ref' => $msgId];
}

function sms_send(string $to, string $message): array {
    $i = integration('sms_at');
    if (!$i || !$i['is_active'] || $i['simulate']) return ['status' => 'simulated', 'ref' => null];
    $url = $i['environment'] === 'live' ? 'https://api.africastalking.com/version1/messaging' : 'https://api.sandbox.africastalking.com/version1/messaging';
    $body = http_build_query(['username' => $i['cfg']['username'] ?? 'sandbox', 'to' => $to, 'message' => $message] + (!empty($i['cfg']['sender_id']) ? ['from' => $i['cfg']['sender_id']] : []));
    $r = http_request('POST', $url, ['apiKey: ' . ($i['creds']['api_key'] ?? ''), 'Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'], $body, 'sms_at');
    integration_mark('sms_at', $r['ok'], $r['ok'] ? null : $r['body']);
    if (!$r['ok']) throw new RuntimeException('SMS failed: HTTP ' . $r['status']);
    return ['status' => 'sent', 'ref' => $r['json']['SMSMessageData']['Recipients'][0]['messageId'] ?? null];
}

/** Ask the LLM to turn KPIs into prioritised insights. Returns ['insights'=>[...], 'model'=>...] */
function anthropic_insights(array $kpis, array $ruleInsights): ?array {
    $i = integration('anthropic');
    $model = $i['cfg']['model'] ?? 'claude-sonnet-4-5';
    $prompt = "You are a property-management analyst for a Kenyan agency. Using these portfolio KPIs (JSON) and rule-based flags, write 4-7 prioritised insights.\n"
        . "Return ONLY a JSON array; each item: {\"sev\":\"critical|warning|info|good\",\"title\":\"<12 words\",\"detail\":\"1-2 sentences with numbers\",\"action\":\"one concrete next step\"}.\n\nKPIs: "
        . json_encode($kpis) . "\n\nRule flags: " . json_encode(array_map(fn($x) => $x['title'], $ruleInsights));
    $r = http_request('POST', 'https://api.anthropic.com/v1/messages', ['x-api-key: ' . ($i['creds']['api_key'] ?? ''), 'anthropic-version: 2023-06-01'],
        ['model' => $model, 'max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => $prompt]]], 'anthropic', null, 60);
    integration_mark('anthropic', $r['ok'], $r['ok'] ? null : $r['body']);
    if (!$r['ok']) return null;
    $text = $r['json']['content'][0]['text'] ?? '';
    if (!preg_match('/\[.*\]/s', $text, $m)) return null;
    $items = json_decode($m[0], true);
    if (!is_array($items)) return null;
    $items = array_map(fn($x) => ['sev' => in_array($x['sev'] ?? '', ['critical', 'warning', 'info', 'good'], true) ? $x['sev'] : 'info', 'title' => (string)($x['title'] ?? ''), 'detail' => (string)($x['detail'] ?? ''), 'action' => (string)($x['action'] ?? ''), 'link' => null], $items);
    return ['insights' => $items, 'model' => $model];
}
