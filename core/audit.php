<?php
/** Audit trail — every create/update/delete/money movement is recorded. */
function audit(string $module, string $action, ?string $entityType = null, $entityId = null, ?string $summary = null, ?array $old = null, ?array $new = null): void {
    [$type, $id, $name] = audit_actor();
    if ($old !== null && $new !== null) { // keep only changed keys
        $changedOld = []; $changedNew = [];
        foreach ($new as $k => $v) if (!array_key_exists($k, $old) || (string)$old[$k] !== (string)$v) { $changedOld[$k] = $old[$k] ?? null; $changedNew[$k] = $v; }
        $old = $changedOld ?: null; $new = $changedNew ?: null;
    }
    try {
        insert('sys_audit_log', [
            'actor_type' => $type, 'actor_id' => $id, 'actor_name' => $name, 'module' => $module, 'action' => $action,
            'entity_type' => $entityType, 'entity_id' => $entityId !== null ? (int)$entityId : null, 'summary' => $summary ? mb_substr($summary, 0, 255) : null,
            'old_values' => $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null, 'new_values' => $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
            'ip_address' => client_ip(), 'user_agent' => user_agent(),
        ]);
    } catch (Throwable $e) { error_log('[audit] ' . $e->getMessage()); }
}
function audit_actor(): array {
    if (VK_APP === 'portal' && ($p = portal_party())) return ['portal', (int)$p['id'], $p['display_name']];
    if (($u = staff())) return ['staff', (int)$u['id'], trim($u['first_name'] . ' ' . $u['last_name'])];
    return ['system', null, 'System'];
}
