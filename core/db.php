<?php
/** PDO layer for the PMS DB (db()) and the ecommerce DB (ecom_db()). */
function _pdo(array $c): PDO {
    $pdo = new PDO("mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}", $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true, PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    return $pdo;
}
function db(): PDO { static $p; return $p ??= _pdo(config('db')); }
function ecom_db(): PDO { static $p; return $p ??= _pdo(config('ecom_db')); }

function _bind(PDOStatement $st, array $params): void {
    foreach ($params as $k => $v) {
        $name = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ":$k");
        $type = is_int($v) || is_bool($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $st->bindValue($name, is_bool($v) ? (int)$v : $v, $type);
    }
}
function q(string $sql, array $p = [], ?PDO $pdo = null): PDOStatement { $st = ($pdo ?? db())->prepare($sql); _bind($st, $p); $st->execute(); return $st; }
function one(string $sql, array $p = [], ?PDO $pdo = null): ?array { $r = q($sql, $p, $pdo)->fetch(); return $r ?: null; }
function all(string $sql, array $p = [], ?PDO $pdo = null): array { return q($sql, $p, $pdo)->fetchAll(); }
function val(string $sql, array $p = [], ?PDO $pdo = null) { $r = q($sql, $p, $pdo)->fetchColumn(); return $r === false ? null : $r; }
function col(string $sql, array $p = [], ?PDO $pdo = null): array { return q($sql, $p, $pdo)->fetchAll(PDO::FETCH_COLUMN); }

function insert(string $table, array $data, ?PDO $pdo = null): int {
    $cols = array_keys($data);
    q("INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (:" . implode(',:', $cols) . ")", $data, $pdo);
    return (int)($pdo ?? db())->lastInsertId();
}
function update(string $table, array $data, string $where, array $wp = [], ?PDO $pdo = null): int {
    $set = []; $p = [];
    foreach ($data as $k => $v) { $set[] = "`$k` = :s_$k"; $p["s_$k"] = $v; }
    return q("UPDATE `$table` SET " . implode(', ', $set) . " WHERE $where", $p + $wp, $pdo)->rowCount();
}
function tx(callable $fn, ?PDO $pdo = null) {
    $pdo = $pdo ?? db();
    if ($pdo->inTransaction()) return $fn();
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
/** IN (...) helper for int lists */
function in_ints(array $ids): string { $ids = array_values(array_unique(array_map('intval', $ids))); return $ids ? implode(',', $ids) : '0'; }
