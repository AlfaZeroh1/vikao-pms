<?php
/**
 * "Silent" API module core: integration registry (api_integrations) + logged HTTP client.
 * Every provider file in this folder uses integration() for credentials and http_request() for calls.
 */
function integration(string $code): ?array {
    static $c = [];
    if (array_key_exists($code, $c)) return $c[$code];
    $r = one("SELECT * FROM api_integrations WHERE code = ?", [$code]);
    if ($r) {
        $creds = json_decode((string)$r['credentials'], true) ?: [];
        foreach ($creds as $k => $v) $creds[$k] = is_string($v) ? vk_decrypt($v) : $v;
        $r['creds'] = $creds; $r['cfg'] = json_decode((string)$r['settings'], true) ?: [];
    }
    return $c[$code] = $r;
}
function integration_live(string $code): bool { $i = integration($code); return $i && $i['is_active'] && !$i['simulate']; }
function integration_mark(string $code, bool $ok, ?string $error = null): void {
    $ok ? q("UPDATE api_integrations SET last_success_at = NOW(), last_error = NULL WHERE code = ?", [$code]) : q("UPDATE api_integrations SET last_error = ? WHERE code = ?", [mb_substr((string)$error, 0, 500), $code]);
}

/** cURL wrapper with api_logs. $headers: ['Name: value', ...] */
function http_request(string $method, string $url, array $headers = [], $body = null, string $logCode = 'http', ?array $basicAuth = null, int $timeout = 30): array {
    $ch = curl_init($url);
    $payload = is_array($body) ? json_encode($body) : $body;
    if (is_array($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => true]);
    if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    if ($basicAuth) curl_setopt($ch, CURLOPT_USERPWD, $basicAuth[0] . ':' . $basicAuth[1]);
    $t = microtime(true); $resp = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $ok = $resp !== false && $status >= 200 && $status < 300;
    try {
        insert('api_logs', ['integration_code' => $logCode, 'direction' => 'outbound', 'endpoint' => mb_substr($method . ' ' . $url, 0, 255), 'http_status' => $status ?: null,
            'request_body' => is_string($payload) ? mb_substr(preg_replace('/("(?:password|Password|secret|api_key|passkey)"\s*:\s*")[^"]*/', '$1***', $payload), 0, 20000) : null,
            'response_body' => $resp === false ? $err : mb_substr((string)$resp, 0, 20000), 'duration_ms' => (int)((microtime(true) - $t) * 1000), 'success' => (int)$ok]);
    } catch (Throwable $e) {}
    return ['ok' => $ok, 'status' => $status, 'body' => $resp === false ? '' : (string)$resp, 'json' => $resp ? json_decode((string)$resp, true) : null, 'error' => $err];
}
function log_inbound(string $code, string $endpoint, string $body, int $status = 200, bool $ok = true): void {
    try { insert('api_logs', ['integration_code' => $code, 'direction' => 'inbound', 'endpoint' => mb_substr($endpoint, 0, 255), 'http_status' => $status, 'request_body' => mb_substr($body, 0, 20000), 'success' => (int)$ok]); } catch (Throwable $e) {}
}
