<?php
/** Meter readings: capture (bulk or field), validation, anomaly detection, assignments. */

function meter_last_reading(int $meterId): float {
    $v = val("SELECT current_reading FROM prpt_meter_readings WHERE meter_id = ? AND status <> 'rejected' ORDER BY reading_date DESC, id DESC LIMIT 1", [$meterId]);
    return $v !== null ? (float)$v : (float)val("SELECT initial_reading FROM prpt_meters WHERE id = ?", [$meterId]);
}
function meter_avg_consumption(int $meterId, int $n = 3): ?float {
    $v = col("SELECT consumption FROM prpt_meter_readings WHERE meter_id = ? AND status <> 'rejected' ORDER BY reading_date DESC, id DESC LIMIT $n", [$meterId]);
    return $v ? array_sum(array_map('floatval', $v)) / count($v) : null;
}

/**
 * Save one reading. $o: date, photo_path, source, assignment_id, notes, lat, lng, allow_rollover
 * Field readings go to 'pending' when setting readings_require_approval is on.
 */
function reading_save(int $meterId, float $current, array $o = []): array {
    $m = one("SELECT m.*, bt.name AS bt_name FROM prpt_meters m JOIN cfg_bill_types bt ON bt.id = m.bill_type_id WHERE m.id = ?", [$meterId]);
    if (!$m || $m['status'] !== 'active') throw new ValidationError(['reading' => 'Meter is not active.']);
    $date = $o['date'] ?? date('Y-m-d');
    if ($date > date('Y-m-d')) throw new ValidationError(['date' => 'Reading date cannot be in the future.']);
    $prev = meter_last_reading($meterId);
    if ($current < $prev && empty($o['allow_rollover'])) throw new ValidationError(['reading' => "Reading (" . num($current, 2) . ") is lower than the previous reading (" . num($prev, 2) . ")."]);
    $cons = round(($current - $prev) * (float)$m['multiplier'], 3);
    $flag = null; $avg = meter_avg_consumption($meterId);
    if ($avg !== null && $avg > 0 && $cons > $avg * (float)setting('reading_anomaly_factor', 2.5)) $flag = round($cons / $avg, 1) . '× usual consumption';
    elseif ($avg !== null && $avg > 0 && $cons == 0.0) $flag = 'Zero consumption';
    $status = ($o['source'] ?? 'bulk') === 'field' && setting('readings_require_approval', false) ? 'pending' : 'approved';
    if ($flag && setting('readings_hold_anomalies', true)) $status = 'pending';
    if (setting('reading_photo_required', true) && ($o['source'] ?? '') === 'field' && empty($o['photo_path'])) throw new ValidationError(['photo' => 'Take a photo of the meter as proof.']);
    $id = insert('prpt_meter_readings', ['meter_id' => $meterId, 'unit_id' => $m['unit_id'], 'assignment_id' => nullable($o['assignment_id'] ?? null), 'reading_date' => $date,
        'previous_reading' => $prev, 'current_reading' => $current, 'consumption' => $cons, 'photo_path' => nullable($o['photo_path'] ?? null),
        'latitude' => nullable($o['lat'] ?? null), 'longitude' => nullable($o['lng'] ?? null), 'source' => $o['source'] ?? 'bulk', 'status' => $status, 'anomaly_flag' => $flag,
        'read_by' => staff_id(), 'notes' => nullable($o['notes'] ?? null)]);
    if (!empty($o['assignment_id'])) {
        q("UPDATE prpt_reading_assignment_items SET reading_id = ?, status = 'done' WHERE assignment_id = ? AND meter_id = ?", [$id, $o['assignment_id'], $meterId]);
        assignment_refresh((int)$o['assignment_id']);
    }
    audit('property', 'reading', 'meter', $meterId, "Reading {$m['serial_no']}: " . num($current, 2) . " (+$cons)" . ($flag ? " ⚠ $flag" : ''));
    return one("SELECT * FROM prpt_meter_readings WHERE id = ?", [$id]);
}

function assignment_create(array $d, array $meterIds): int {
    if (!$meterIds) throw new ValidationError(['meters' => 'Select at least one meter / unit.']);
    $id = insert('prpt_reading_assignments', ['title' => $d['title'], 'property_id' => (int)$d['property_id'], 'bill_type_id' => nullable($d['bill_type_id'] ?? null),
        'assigned_to' => (int)$d['assigned_to'], 'period_month' => $d['period_month'] ?? date('Y-m'), 'due_date' => $d['due_date'], 'require_photo' => (int)($d['require_photo'] ?? 1),
        'notes' => nullable($d['notes'] ?? null), 'created_by' => staff_id()]);
    foreach (array_unique(array_map('intval', $meterIds)) as $mid) insert('prpt_reading_assignment_items', ['assignment_id' => $id, 'meter_id' => $mid]);
    staff_notify((int)$d['assigned_to'], 'reading_assignment', 'New meter reading assignment', $d['title'] . ' · ' . count($meterIds) . ' meters · due ' . fdate($d['due_date']), 'fa-gauge-high', page('property/field-reading', ['assignment' => $id]));
    $u = one("SELECT email, first_name FROM sys_users WHERE id = ?", [$d['assigned_to']]);
    if ($u) send_message('email', $u['email'], 'New meter reading assignment', email_wrap('Meter reading assignment', '<p>Hi ' . e($u['first_name']) . ', you have been assigned <b>' . e($d['title']) . '</b> (' . count($meterIds) . ' meters), due ' . fdate($d['due_date']) . '.</p><p><a href="' . e(page('property/field-reading', ['assignment' => $id])) . '">Open the reader app</a></p>'), ['user_id' => (int)$d['assigned_to']]);
    audit('property', 'create', 'reading_assignment', $id, $d['title']);
    return $id;
}
function assignment_refresh(int $id): void {
    $s = one("SELECT COUNT(*) total, SUM(status <> 'pending') done FROM prpt_reading_assignment_items WHERE assignment_id = ?", [$id]);
    $a = one("SELECT * FROM prpt_reading_assignments WHERE id = ?", [$id]);
    if ((int)$s['done'] >= (int)$s['total'] && $a['status'] !== 'completed') {
        q("UPDATE prpt_reading_assignments SET status = 'completed', completed_at = NOW() WHERE id = ?", [$id]);
        if ($a['created_by']) staff_notify((int)$a['created_by'], 'reading_done', 'Reading assignment completed', $a['title'], 'fa-circle-check', page('property/readings', ['assignment' => $id]));
    } elseif ((int)$s['done'] > 0 && $a['status'] === 'assigned') q("UPDATE prpt_reading_assignments SET status = 'in_progress' WHERE id = ?", [$id]);
}
