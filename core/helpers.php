<?php
/** General helpers shared by the PMS and the portal. */

function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------------- URLs (relative to the project root) ---------------- */
function root_path(): string {
    static $bp = null;
    if ($bp !== null) return $bp;
    if (($cfg = config('app.base_path')) !== null) return $bp = rtrim($cfg, '/');
    $doc = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $root = realpath(VK_ROOT) ?: VK_ROOT;
    return $bp = rtrim(($doc && str_starts_with($root, $doc)) ? str_replace('\\', '/', substr($root, strlen($doc))) : '', '/');
}
function url(string $path = '', array $query = []): string {
    $query = array_filter($query, fn($v) => $v !== null && $v !== '' && $v !== []);
    return root_path() . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}
/** PMS page link. Relative inside the PMS, absolute (config app.pms_url) from the portal / emails. */
function page(string $route, array $q = []): string {
    $q = array_filter(['r' => $route] + $q, fn($v) => $v !== null && $v !== '');
    return (VK_APP === 'admin' ? root_path() . '/' : rtrim((string)config('app.pms_url'), '/') . '/') . '?' . http_build_query($q);
}
/** Portal page link. Relative inside the portal, absolute (config app.portal_url) elsewhere. */
function portal_page(string $route = 'home', array $q = []): string {
    $q = array_filter(['p' => $route] + $q, fn($v) => $v !== null && $v !== '');
    return (VK_APP === 'portal' ? root_path() . '/' : rtrim((string)config('app.portal_url'), '/') . '/') . '?' . http_build_query($q);
}
function asset(string $path): string {
    $f = VK_ROOT . '/assets/' . ltrim($path, '/');
    return url('assets/' . ltrim($path, '/')) . '?v=' . (is_file($f) ? filemtime($f) : 1);
}
function file_url(?string $path): ?string { return $path ? url('file.php', ['f' => $path]) : null; }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function is_ajax(): bool { return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'; }

/* ---------------- input ---------------- */
function input(string $k, $d = null) { $v = $_POST[$k] ?? $_GET[$k] ?? $d; return is_string($v) ? trim($v) : $v; }
function input_int(string $k, ?int $d = null): ?int { $v = input($k); return ($v === null || $v === '' || !is_numeric($v)) ? $d : (int)$v; }
function input_num(string $k, ?float $d = null): ?float { $v = input($k); if (is_string($v)) $v = str_replace([',', ' '], '', $v); return ($v === null || $v === '' || !is_numeric($v)) ? $d : (float)$v; }
function input_array(string $k): array {
    $v = $_POST[$k] ?? $_GET[$k] ?? [];
    if (is_string($v)) $v = $v === '' ? [] : explode(',', $v);
    return array_values(array_filter((array)$v, fn($x) => $x !== '' && $x !== null));
}
function input_date(string $k, ?string $d = null): ?string { $v = (string)input($k, ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $d; }
function nullable($v) { return ($v === '' || $v === null) ? null : $v; }

/* ---------------- JSON ---------------- */
function json_out(array $d, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); exit;
}
function json_ok(array $d = [], string $msg = 'Done'): never { json_out(['ok' => true, 'message' => $msg] + $d); }
function json_fail(string $msg, int $status = 422, array $errors = []): never { json_out(['ok' => false, 'message' => $msg, 'errors' => $errors], $status); }

class ValidationError extends RuntimeException {
    public array $errors;
    public function __construct(array $errors, string $msg = 'Please fix the highlighted fields.') { parent::__construct($msg); $this->errors = $errors; }
}
/** Simple validator: rules = ['field' => 'required|email|numeric|min:3|date|in:a,b'] */
function validate(array $data, array $rules): array {
    $err = [];
    foreach ($rules as $f => $spec) {
        $v = $data[$f] ?? null; $s = is_string($v) ? trim($v) : $v;
        foreach (explode('|', $spec) as $rule) {
            [$r, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $empty = $s === null || $s === '' || $s === [];
            if ($r === 'required' && $empty) { $err[$f] = 'This field is required.'; break; }
            if ($empty) continue;
            if ($r === 'email' && !filter_var($s, FILTER_VALIDATE_EMAIL)) $err[$f] = 'Enter a valid email.';
            if ($r === 'numeric' && !is_numeric(str_replace(',', '', (string)$s))) $err[$f] = 'Enter a number.';
            if ($r === 'positive' && (float)str_replace(',', '', (string)$s) <= 0) $err[$f] = 'Must be greater than zero.';
            if ($r === 'min' && mb_strlen((string)$s) < (int)$arg) $err[$f] = "At least $arg characters.";
            if ($r === 'max' && mb_strlen((string)$s) > (int)$arg) $err[$f] = "At most $arg characters.";
            if ($r === 'date' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s)) $err[$f] = 'Enter a valid date.';
            if ($r === 'in' && !in_array((string)$s, explode(',', $arg), true)) $err[$f] = 'Invalid choice.';
            if (isset($err[$f])) break;
        }
    }
    if ($err) throw new ValidationError($err);
    return $data;
}

/* ---------------- CSRF / flash ---------------- */
function csrf_token(): string { return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        if (is_ajax() || str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'api')) json_fail('Session expired — refresh the page.', 419);
        http_response_code(419); exit('Session expired. Go back and refresh.');
    }
}
function flash(string $type, string $msg): void { $_SESSION['_flash'][] = compact('type', 'msg'); }
function take_flashes(): array { $f = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $f; }

/* ---------------- ids & formatting ---------------- */
function uuid4(): string { $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4)); }
function random_code(int $n = 6, string $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'): string { $s = ''; for ($i = 0; $i < $n; $i++) $s .= $a[random_int(0, strlen($a) - 1)]; return $s; }
function money($a, bool $symbol = true, bool $short = false): string {
    if ($a === null || $a === '') return '—';
    $a = (float)$a; $neg = $a < 0; $x = abs($a);
    if ($short && $x >= 1e6) $s = rtrim(rtrim(number_format($x / 1e6, 2), '0'), '.') . 'M';
    elseif ($short && $x >= 1e4) $s = rtrim(rtrim(number_format($x / 1e3, 1), '0'), '.') . 'K';
    else $s = number_format($x, 2);
    return ($neg ? '-' : '') . ($symbol ? 'KSh ' : '') . $s;
}
function num($v, int $dp = 0): string { return $v === null ? '—' : number_format((float)$v, $dp); }
function round2($v): float { return round((float)$v, 2); }
function fdate(?string $d, string $f = 'j M Y'): string { return $d ? date($f, strtotime($d)) : '—'; }
function fdatetime(?string $d): string { return $d ? date('j M Y, g:i a', strtotime($d)) : '—'; }
function time_ago(?string $dt): string {
    if (!$dt) return '—'; $d = time() - strtotime($dt);
    if ($d < 60) return 'just now'; if ($d < 3600) return floor($d / 60) . 'm ago'; if ($d < 86400) return floor($d / 3600) . 'h ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago'; return date('j M Y', strtotime($dt));
}
function month_label(string $ym): string { return date('F Y', strtotime($ym . '-01')); }
function initials(string $n): string { $p = preg_split('/\s+/', trim($n)); return strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1)); }
function plural(int $n, string $one, ?string $many = null): string { return $n . ' ' . ($n === 1 ? $one : ($many ?? $one . 's')); }
function excerpt(?string $t, int $n = 120): string { $t = trim(preg_replace('/\s+/', ' ', (string)$t)); return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t; }
function normalize_phone(?string $p): ?string {
    $p = preg_replace('/\D+/', '', (string)$p); if ($p === '') return null;
    if (str_starts_with($p, '0')) $p = '254' . substr($p, 1); elseif (strlen($p) === 9) $p = '254' . $p;
    return (strlen($p) >= 11 && strlen($p) <= 13) ? '+' . $p : null;
}
function slugify(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-'); }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45); }
function user_agent(): string { return substr($_SERVER['HTTP_USER_AGENT'] ?? 'cli', 0, 255); }

/** Placeholder renderer: {{tenant_name}}, {{amount|money}} */
function render_placeholders(string $tpl, array $vars): string {
    return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)(?:\|([a-z]+))?\s*\}\}/i', function ($m) use ($vars) {
        $v = $vars[$m[1]] ?? '';
        if (($m[2] ?? '') === 'money') return money($v);
        if (($m[2] ?? '') === 'date') return fdate((string)$v);
        if (($m[2] ?? '') === 'upper') return strtoupper((string)$v);
        return (string)$v;
    }, $tpl);
}
function sanitize_html(string $html): string {
    $html = strip_tags($html, '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><table><thead><tbody><tr><td><th><a><span><div><hr><blockquote>');
    return preg_replace(['/\son\w+\s*=\s*"[^"]*"/i', "/\son\w+\s*=\s*'[^']*'/i", '/javascript:/i'], '', $html);
}

function rate_limit(string $bucket, int $max, int $window): bool {
    $r = one("SELECT hits, window_ends FROM sys_rate_limits WHERE bucket = ?", [$bucket]);
    if (!$r || strtotime($r['window_ends']) < time()) { q("REPLACE INTO sys_rate_limits (bucket, hits, window_ends) VALUES (?, 1, ?)", [$bucket, date('Y-m-d H:i:s', time() + $window)]); return true; }
    if ((int)$r['hits'] >= $max) return false;
    q("UPDATE sys_rate_limits SET hits = hits + 1 WHERE bucket = ?", [$bucket]); return true;
}

/* ---------------- tiny view helpers ---------------- */
function view(string $file, array $vars = []): void { extract($vars, EXTR_SKIP); include $file; }
function partial(string $name, array $vars = []): void { view(VK_ROOT . "/shared/partials/$name.php", $vars); }
function capture(callable $fn): string { ob_start(); $fn(); return ob_get_clean(); }

const PILL_TONES = [
    'green' => 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300', 'amber' => 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    'red' => 'bg-rose-500/15 text-rose-700 dark:text-rose-300', 'blue' => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    'violet' => 'bg-violet-500/15 text-violet-700 dark:text-violet-300', 'slate' => 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
];
const STATUS_TONE = [
    'active' => 'green', 'paid' => 'green', 'allocated' => 'green', 'resolved' => 'green', 'closed' => 'slate', 'completed' => 'green', 'approved' => 'green', 'done' => 'green',
    'occupied' => 'green', 'confirmed' => 'green', 'checked_in' => 'blue', 'checked_out' => 'slate', 'sent' => 'green', 'delivered' => 'green', 'held' => 'blue', 'won' => 'green',
    'vacant' => 'amber', 'pending' => 'amber', 'partially_paid' => 'amber', 'partially_allocated' => 'amber', 'in_progress' => 'blue', 'assigned' => 'blue', 'notice' => 'amber',
    'issued' => 'blue', 'open' => 'blue', 'draft' => 'slate', 'reserved' => 'violet', 'owner_occupied' => 'violet', 'sold' => 'violet', 'awaiting_customer' => 'violet', 'simulated' => 'violet',
    'overdue' => 'red', 'unallocated' => 'red', 'void' => 'slate', 'reversed' => 'red', 'failed' => 'red', 'cancelled' => 'slate', 'terminated' => 'red', 'ended' => 'slate', 'rejected' => 'red',
    'urgent' => 'red', 'high' => 'amber', 'medium' => 'blue', 'low' => 'slate', 'under_maintenance' => 'red', 'suspended' => 'red', 'on_hold' => 'slate', 'queued' => 'slate', 'no_show' => 'red',
];
function pill(?string $status, ?string $label = null, ?string $tone = null): string {
    $status = (string)$status; $tone = $tone ?? (STATUS_TONE[$status] ?? 'slate');
    return '<span class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-bold ' . PILL_TONES[$tone] . '">' . e($label ?? ucwords(str_replace('_', ' ', $status))) . '</span>';
}
