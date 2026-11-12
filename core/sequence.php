<?php
/** Human document numbers: INV-2026-00042, RCT-2026-00007 … (row-locked, gap-free per year) */
function next_no(string $key): string {
    return tx(function () use ($key) {
        $s = one("SELECT * FROM cfg_sequences WHERE seq_key = ? FOR UPDATE", [$key]);
        if (!$s) { insert('cfg_sequences', ['seq_key' => $key, 'prefix' => strtoupper(substr($key, 0, 3)), 'next_value' => 1, 'current_year' => (int)date('Y')]); $s = one("SELECT * FROM cfg_sequences WHERE seq_key = ?", [$key]); }
        $year = (int)date('Y'); $n = (int)$s['next_value'];
        if ($s['reset_yearly'] && (int)$s['current_year'] !== $year) $n = 1;
        q("UPDATE cfg_sequences SET next_value = ?, current_year = ? WHERE seq_key = ?", [$n + 1, $year, $key]);
        return $s['prefix'] . '-' . ($s['reset_yearly'] ? $year . '-' : '') . str_pad((string)$n, (int)$s['pad_length'], '0', STR_PAD_LEFT);
    });
}
