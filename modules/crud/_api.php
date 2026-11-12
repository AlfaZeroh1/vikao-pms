<?php
/** Generic CRUD save/delete (permission checked per entity) */
return [
    'save' => [null, function () {
        $key = (string)input('e'); $e = crud_get($key);
        if (!can($e['perm'])) json_fail('No permission.', 403);
        $id = input_int('id'); $data = []; $errors = [];
        foreach ($e['fields'] as $n => $f) {
            [$label, $type, $opts, $req] = array_pad($f, 4, null);
            $v = $_POST[$n] ?? null; if (is_string($v)) $v = trim($v);
            if ($type === 'bool') $v = !empty($v) && $v !== '0' ? 1 : 0;
            elseif (in_array($type, ['money', 'number'], true)) $v = ($v === '' || $v === null) ? null : (float)str_replace(',', '', (string)$v);
            elseif ($type === 'datetime-local' && $v) $v = date('Y-m-d H:i:s', strtotime($v));
            elseif (in_array($type, ['party', 'unit'], true) || ($type === 'select' && preg_match('/_id$/', $n))) $v = ($v === '' || $v === null) ? null : (int)$v;
            elseif ($type === 'html') $v = sanitize_html((string)$v);
            else $v = ($v === '') ? null : $v;
            if ($req && ($v === null || $v === '')) $errors[$n] = $label . ' is required.';
            if ($type === 'select' && $v !== null && !is_int($v)) { $o = is_callable($opts) ? $opts() : $opts; if (!array_key_exists($v, $o)) $errors[$n] = 'Invalid choice.'; }
            $data[$n] = $v;
        }
        if (isset($e['parent']) && ($pv = input_int($e['parent']))) $data[$e['parent']] = $pv;
        if (isset($data['code']) && $data['code'] !== null && in_array($key, ['bill_types', 'tax_rates'], true)) $data['code'] = strtoupper(preg_replace('/\W/', '_', $data['code']));
        if ($errors) throw new ValidationError($errors);
        if ($id) {
            $old = one("SELECT * FROM `{$e['table']}` WHERE id = ?", [$id]);
            if (!$old) json_fail('Record not found.', 404);
            update($e['table'], $data, 'id = :id', ['id' => $id]);
            audit(explode('.', $e['perm'])[0], 'update', $e['table'], $id, 'Updated ' . strtolower($e['title']), $old, $data);
        } else {
            $id = insert($e['table'], $data);
            audit(explode('.', $e['perm'])[0], 'create', $e['table'], $id, 'Created ' . strtolower($e['title']), null, $data);
        }
        json_ok(['id' => $id], $e['title'] . ' saved.');
    }],
    'delete' => [null, function () {
        $e = crud_get((string)input('e')); if (!can($e['perm'])) json_fail('No permission.', 403);
        $id = (int)input_int('id'); $old = one("SELECT * FROM `{$e['table']}` WHERE id = ?", [$id]);
        if (!$old) json_fail('Not found.', 404);
        if (!empty($e['protect']) && !empty($old[$e['protect']])) json_fail('This is a system record and cannot be deleted.');
        try { q("DELETE FROM `{$e['table']}` WHERE id = ?", [$id]); }
        catch (PDOException $ex) { json_fail('It is in use elsewhere — deactivate it instead of deleting.'); }
        audit(explode('.', $e['perm'])[0], 'delete', $e['table'], $id, 'Deleted ' . strtolower($e['title']), $old, []);
        json_ok([], 'Deleted.');
    }],
];
