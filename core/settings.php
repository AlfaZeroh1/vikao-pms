<?php
/** Key/value settings (cfg_settings) with request cache. */
function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (all("SELECT setting_key, setting_value, value_type FROM cfg_settings") as $r) $cache[$r['setting_key']] = $r; } catch (Throwable $e) { return $default; }
    }
    if (!isset($cache[$key])) return $default;
    $v = $cache[$key]['setting_value'];
    return match ($cache[$key]['value_type']) {
        'int' => (int)$v, 'decimal' => (float)$v, 'bool' => $v === '1' || $v === 'true', 'json' => json_decode((string)$v, true), default => $v,
    };
}
function set_setting(string $key, $value): void {
    q("UPDATE cfg_settings SET setting_value = ? WHERE setting_key = ?", [is_array($value) ? json_encode($value) : (string)$value, $key]);
}

/** Symmetric encryption for integration secrets */
function vk_encrypt(?string $plain): ?string {
    if ($plain === null || $plain === '') return $plain;
    $key = hash('sha256', (string)config('app.key'), true); $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:' . base64_encode($iv . $tag . $ct);
}
function vk_decrypt(?string $v): ?string {
    if (!$v || !str_starts_with($v, 'enc:')) return $v;
    $raw = base64_decode(substr($v, 4)); $key = hash('sha256', (string)config('app.key'), true);
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? null : $out;
}
