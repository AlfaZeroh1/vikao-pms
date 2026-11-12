<?php
/** Property module AJAX actions */
return [
    'search-parties' => [null, function () {
        $q = '%' . trim((string)input('q', '')) . '%'; $role = (string)input('role', '');
        $w = "deleted_at IS NULL AND (display_name LIKE :q OR phone LIKE :q OR email LIKE :q OR id_number LIKE :q OR party_no LIKE :q)";
        if (in_array($role, ['owner', 'tenant', 'guest', 'buyer'], true)) $w .= " AND is_$role = 1";
        $rows = all("SELECT id, display_name, phone, email, party_no, is_owner, is_tenant FROM prpt_parties WHERE $w ORDER BY display_name LIMIT 15", ['q' => $q]);
        json_ok(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['display_name'], 'sub' => trim($r['party_no'] . ' · ' . ($r['phone'] ?: $r['email']) . ($r['is_owner'] ? ' · owner' : '') . ($r['is_tenant'] ? ' · tenant' : ''), ' ·')], $rows)]);
    }],
    'search-units' => [null, function () {
        $q = '%' . trim((string)input('q', '')) . '%'; $p = ['q' => $q]; $w = "u.deleted_at IS NULL AND (u.unit_no LIKE :q OR pr.name LIKE :q OR CONCAT(COALESCE(b.code,''), u.unit_no) LIKE :q) AND " . scope_sql('u.property_id');
        if ($pid = input_int('property_id')) { $w .= ' AND u.property_id = :pid'; $p['pid'] = $pid; }
        $rows = all("SELECT u.id, u.unit_no, u.status, pr.name, b.code AS block_code FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id LEFT JOIN prpt_blocks b ON b.id = u.block_id WHERE $w ORDER BY pr.name, u.unit_no LIMIT 20", $p);
        json_ok(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'label' => $r['unit_no'] . ' · ' . $r['name'], 'sub' => ($r['block_code'] ? 'Block ' . $r['block_code'] . ' · ' : '') . ucfirst(str_replace('_', ' ', $r['status']))], $rows)]);
    }],
    'floors' => [null, function () {
        $rows = all("SELECT id, name FROM prpt_floors WHERE property_id = ? AND (block_id <=> ?) ORDER BY level", [input_int('property_id'), input_int('block_id')]);
        $out = ['items' => $rows];
        if (input('blocks')) $out['blocks'] = all("SELECT id, CONCAT(code, ' — ', name) AS name FROM prpt_blocks WHERE property_id = ? ORDER BY sort_order, code", [input_int('property_id')]);
        json_ok($out);
    }],
    'meters-for' => [null, function () {
        $w = ["m.property_id = :p", "m.status = 'active'", 'm.unit_id IS NOT NULL']; $p = ['p' => input_int('property_id')];
        if ($bt = input_int('bill_type_id')) { $w[] = 'm.bill_type_id = :bt'; $p['bt'] = $bt; }
        if ($b = input_int('block_id')) { $w[] = 'u.block_id = :b'; $p['b'] = $b; }
        if ($u = input_int('unit_id')) { $w[0] = 'm.unit_id = :u'; $p = ['u' => $u] + array_diff_key($p, ['p' => 1]); }
        json_ok(['items' => all("SELECT m.id, m.serial_no, m.multiplier, u.unit_no, bt.name AS bt, bt.unit_label, (SELECT current_reading FROM prpt_meter_readings r WHERE r.meter_id = m.id AND r.status <> 'rejected' ORDER BY reading_date DESC, id DESC LIMIT 1) AS last_reading, m.initial_reading FROM prpt_meters m JOIN prpt_units u ON u.id = m.unit_id JOIN cfg_bill_types bt ON bt.id = m.bill_type_id WHERE " . implode(' AND ', $w) . " ORDER BY u.unit_no", $p)]);
    }],

    /* ---------- properties ---------- */
    'save-property' => ['property.properties.manage', function () {
        $d = validate($_POST, ['name' => 'required|min:3', 'code' => 'required|max:20', 'property_type' => 'required', 'invoice_day' => 'numeric', 'due_day' => 'numeric']);
        $id = input_int('id'); $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $d['code']));
        if (val("SELECT 1 FROM prpt_properties WHERE code = ? AND id <> ?", [$code, $id ?? 0])) throw new ValidationError(['code' => 'Code already used.']);
        $row = ['code' => $code, 'name' => $d['name'], 'property_type' => $d['property_type'], 'management_type' => input('management_type', 'full'), 'county' => nullable(input('county')),
            'area' => nullable(input('area')), 'address' => nullable(input('address')), 'lr_number' => nullable(input('lr_number')), 'year_built' => input_int('year_built'),
            'description' => nullable(input('description')), 'caretaker_name' => nullable(input('caretaker_name')), 'caretaker_phone' => nullable(input('caretaker_phone')),
            'manager_user_id' => input_int('manager_user_id'), 'invoice_day' => max(1, min(28, (int)input_int('invoice_day', 1))), 'due_day' => max(1, min(28, (int)input_int('due_day', 5))),
            'status' => input('status', 'active'), 'ecom_location_id' => input_int('ecom_location_id')];
        if ($id) { $old = one("SELECT * FROM prpt_properties WHERE id = ?", [$id]); update('prpt_properties', $row, 'id = :id', ['id' => $id]); audit('property', 'update', 'property', $id, 'Updated property ' . $row['name'], $old, $row); }
        else {
            $id = insert('prpt_properties', $row + ['uuid' => uuid4()]);
            // default: collect into the Vikao master paybill + bank
            foreach (col("SELECT id FROM fin_accounts WHERE is_master = 1 AND is_active = 1") as $i => $acc) insert('fin_property_accounts', ['property_id' => $id, 'account_id' => $acc, 'account_reference_format' => '{unit_no}', 'is_default' => (int)($i === 0)]);
            audit('property', 'create', 'property', $id, 'Created property ' . $row['name']);
        }
        json_ok(['redirect' => page('property/property', ['id' => $id])], 'Property saved.');
    }],
    /** Generate blocks → floors → units in one go */
    'generate-structure' => ['property.properties.manage', function () {
        $pid = (int)input_int('property_id'); $prop = one("SELECT * FROM prpt_properties WHERE id = ?", [$pid]);
        $blocks = max(0, min(26, (int)input_int('blocks', 0))); $floorsFrom = (int)input_int('floor_from', 0); $floorsTo = (int)input_int('floor_to', 4); $perFloor = max(1, min(50, (int)input_int('units_per_floor', 4)));
        $pattern = (string)input('pattern', '{block}{floor}{n}');
        if ($floorsTo < $floorsFrom || $floorsTo - $floorsFrom > 60) throw new ValidationError(['floor_to' => 'Check the floor range.']);
        $created = 0;
        tx(function () use ($pid, $blocks, $floorsFrom, $floorsTo, $perFloor, $pattern, &$created) {
            $blockList = $blocks ? array_map(fn($i) => chr(65 + $i), range(0, $blocks - 1)) : [null];
            foreach ($blockList as $bi => $bcode) {
                $bid = null;
                if ($bcode) { $bid = (int)val("SELECT id FROM prpt_blocks WHERE property_id = ? AND code = ?", [$pid, $bcode]) ?: insert('prpt_blocks', ['property_id' => $pid, 'code' => $bcode, 'name' => 'Block ' . $bcode, 'floors_count' => $floorsTo - $floorsFrom + 1, 'sort_order' => $bi]); }
                for ($f = $floorsFrom; $f <= $floorsTo; $f++) {
                    $fid = (int)val("SELECT id FROM prpt_floors WHERE property_id = ? AND block_id <=> ? AND level = ?", [$pid, $bid, $f]) ?: insert('prpt_floors', ['property_id' => $pid, 'block_id' => $bid, 'level' => $f, 'name' => $f === 0 ? 'Ground floor' : ($f < 0 ? 'Basement ' . abs($f) : 'Floor ' . $f)]);
                    for ($n = 1; $n <= $perFloor; $n++) {
                        $no = strtr($pattern, ['{block}' => $bcode ?? '', '{floor}' => $f === 0 ? 'G' : (string)$f, '{n}' => str_pad((string)$n, 2, '0', STR_PAD_LEFT), '{nn}' => (string)$n]);
                        if (val("SELECT 1 FROM prpt_units WHERE property_id = ? AND unit_no = ?", [$pid, $no])) continue;
                        insert('prpt_units', ['uuid' => uuid4(), 'property_id' => $pid, 'block_id' => $bid, 'floor_id' => $fid, 'unit_no' => $no, 'unit_type' => input('unit_type', 'apartment'),
                            'usage_class' => input('usage_class', 'residential'), 'purpose' => input('purpose', 'rent'), 'bedrooms' => input_int('bedrooms'), 'bathrooms' => input_int('bathrooms'),
                            'size_sqm' => input_num('size_sqm'), 'rent_amount' => input_num('rent_amount'), 'service_charge' => input_num('service_charge'), 'deposit_months' => input_num('deposit_months', 1)]);
                        $created++;
                    }
                }
            }
        });
        audit('property', 'generate', 'property', $pid, "Generated $created units");
        json_ok([], "$created units created.");
    }],

    /* ---------- units ---------- */
    'save-unit' => ['property.properties.manage', function () {
        $d = validate($_POST, ['property_id' => 'required', 'unit_no' => 'required|max:30']);
        $id = input_int('id'); $pid = (int)$d['property_id'];
        if (val("SELECT 1 FROM prpt_units WHERE property_id = ? AND unit_no = ? AND id <> ? AND deleted_at IS NULL", [$pid, $d['unit_no'], $id ?? 0])) throw new ValidationError(['unit_no' => 'This unit number already exists in the property.']);
        $row = ['property_id' => $pid, 'block_id' => input_int('block_id'), 'floor_id' => input_int('floor_id'), 'unit_no' => strtoupper(trim($d['unit_no'])), 'unit_type' => input('unit_type', 'apartment'),
            'usage_class' => input('usage_class', 'residential'), 'purpose' => input('purpose', 'rent'), 'bedrooms' => input_int('bedrooms'), 'bathrooms' => input_int('bathrooms'),
            'size_sqm' => input_num('size_sqm'), 'rent_amount' => input_num('rent_amount'), 'service_charge' => input_num('service_charge'), 'deposit_months' => input_num('deposit_months', 1),
            'sale_price' => input_num('sale_price'), 'furnishing' => nullable(input('furnishing')), 'notes' => nullable(input('notes'))];
        if ($id) { $old = one("SELECT * FROM prpt_units WHERE id = ?", [$id]); update('prpt_units', $row, 'id = :id', ['id' => $id]); if (($st = input('status')) && $st !== $old['status']) set_unit_status($id, $st, 'Manual change'); audit('property', 'update', 'unit', $id, 'Updated unit ' . $row['unit_no'], $old, $row); }
        else { $id = insert('prpt_units', $row + ['uuid' => uuid4(), 'status' => input('status', 'vacant')]); audit('property', 'create', 'unit', $id, 'Created unit ' . $row['unit_no']); }
        json_ok(['id' => $id], 'Unit saved.');
    }],
    'unit-status' => ['property.properties.manage', function () {
        $ids = array_map('intval', input_array('ids') ?: [input_int('id')]); $st = (string)input('status');
        if (!in_array($st, ['vacant', 'occupied', 'reserved', 'sold', 'owner_occupied', 'under_maintenance', 'unavailable'], true)) json_fail('Invalid status.');
        foreach ($ids as $id) set_unit_status($id, $st, (string)input('reason', 'Manual change'));
        audit('property', 'status', 'unit', $ids[0] ?? null, plural(count($ids), 'unit') . " → $st");
        json_ok([], 'Status updated.');
    }],
    'delete-unit' => ['property.properties.manage', function () {
        $id = (int)input_int('id');
        if (unit_active_lease($id)) json_fail('End the active account on this unit first.');
        q("UPDATE prpt_units SET deleted_at = NOW() WHERE id = ?", [$id]); audit('property', 'delete', 'unit', $id, 'Archived unit');
        json_ok([], 'Unit archived.');
    }],

    /* ---------- parties (owners & tenants) ---------- */
    'save-party' => ['property.parties.manage', function () {
        $type = input('party_type', 'individual');
        $rules = $type === 'company' ? ['company_name' => 'required|min:2'] : ['first_name' => 'required|min:2'];
        $d = validate($_POST, $rules + ['email' => 'email']);
        if (!input('email') && !input('phone')) throw new ValidationError(['phone' => 'Give at least a phone number or an email.']);
        $id = input_int('id'); $email = strtolower(trim((string)input('email', '')));
        if ($email && val("SELECT 1 FROM prpt_parties WHERE email = ? AND id <> ? AND deleted_at IS NULL", [$email, $id ?? 0])) throw new ValidationError(['email' => 'Another person already uses this email.']);
        $data = $_POST + ['party_type' => $type]; $data['is_owner'] = !empty($_POST['is_owner']); $data['is_tenant'] = !empty($_POST['is_tenant']);
        if ($id) {
            $old = one("SELECT * FROM prpt_parties WHERE id = ?", [$id]);
            $row = ['party_type' => $type, 'first_name' => nullable(input('first_name')), 'last_name' => nullable(input('last_name')), 'company_name' => nullable(input('company_name')),
                'display_name' => $type === 'company' ? input('company_name') : trim(input('first_name') . ' ' . input('last_name')), 'is_owner' => (int)$data['is_owner'], 'is_tenant' => (int)$data['is_tenant'],
                'id_type' => nullable(input('id_type')), 'id_number' => nullable(input('id_number')), 'kra_pin' => nullable(input('kra_pin')), 'email' => $email ?: null,
                'phone' => normalize_phone(input('phone')) ?? nullable(input('phone')), 'alt_phone' => nullable(input('alt_phone')), 'nationality' => nullable(input('nationality')),
                'date_of_birth' => input_date('date_of_birth'), 'gender' => nullable(input('gender')), 'postal_address' => nullable(input('postal_address')), 'physical_address' => nullable(input('physical_address')),
                'occupation' => nullable(input('occupation')), 'employer' => nullable(input('employer')), 'next_of_kin_name' => nullable(input('next_of_kin_name')), 'next_of_kin_phone' => nullable(input('next_of_kin_phone')),
                'next_of_kin_relation' => nullable(input('next_of_kin_relation')), 'preferred_channel' => input('preferred_channel', 'email'), 'notes' => nullable(input('notes'))];
            update('prpt_parties', $row, 'id = :id', ['id' => $id]);
            if ($row['is_owner'] || $row['is_tenant']) portal_ensure_account($id);
            audit('property', 'update', 'party', $id, 'Updated ' . $row['display_name'], $old, $row);
        } else $id = party_create($data);
        if (input('send_invite') && input('email')) { try { portal_send_activation($id); } catch (Throwable $e) {} }
        json_ok(['id' => $id, 'redirect' => input('stay') ? null : page('property/party', ['id' => $id])], 'Saved.');
    }],
    'portal-invite' => ['property.portal.invite', function () {
        $link = portal_send_activation((int)input_int('party_id'));
        json_ok(['link' => $link], 'Activation link sent.');
    }],
    'portal-status' => ['property.portal.invite', function () {
        $st = input('status') === 'disabled' ? 'disabled' : 'active';
        $a = one("SELECT * FROM prt_accounts WHERE party_id = ?", [input_int('party_id')]);
        if (!$a) json_fail('No portal account.');
        if ($st === 'active' && !$a['password_hash']) json_fail('This account was never activated — send an activation link instead.');
        q("UPDATE prt_accounts SET status = ? WHERE id = ?", [$st, $a['id']]);
        audit('property', 'portal_' . $st, 'party', (int)$a['party_id'], 'Portal account ' . $st);
        json_ok([], 'Portal account ' . $st . '.');
    }],
    'save-bank' => ['property.parties.manage', function () {
        $pid = (int)input_int('party_id');
        if (input('is_default')) q("UPDATE prpt_party_bank_accounts SET is_default = 0 WHERE party_id = ?", [$pid]);
        insert('prpt_party_bank_accounts', ['party_id' => $pid, 'method' => input('method', 'bank'), 'bank_name' => nullable(input('bank_name')), 'branch' => nullable(input('branch')),
            'account_name' => nullable(input('account_name')), 'account_number' => nullable(input('account_number')), 'is_default' => (int)!!input('is_default')]);
        audit('property', 'create', 'party_bank', $pid, 'Added payout account');
        json_ok([], 'Payout account saved.');
    }],
    'delete-bank' => ['property.parties.manage', function () { q("DELETE FROM prpt_party_bank_accounts WHERE id = ?", [input_int('id')]); json_ok([], 'Removed.'); }],

    /* ---------- ownership ---------- */
    'save-ownership' => ['property.ownership.manage', function () {
        $d = validate($_POST, ['party_id' => 'required', 'scope' => 'required|in:property,block,unit', 'property_id' => 'required', 'share_pct' => 'required|numeric', 'start_date' => 'required|date']);
        $scope = $d['scope']; $unitId = $scope === 'unit' ? input_int('unit_id') : null; $blockId = $scope === 'block' ? input_int('block_id') : null;
        if ($scope === 'unit' && !$unitId) throw new ValidationError(['unit_id' => 'Choose the unit.']);
        if ($scope === 'block' && !$blockId) throw new ValidationError(['block_id' => 'Choose the block.']);
        $share = (float)$d['share_pct'];
        $existing = (float)val("SELECT COALESCE(SUM(share_pct),0) FROM prpt_ownerships WHERE scope = ? AND property_id = ? AND (unit_id <=> ?) AND (block_id <=> ?) AND (end_date IS NULL OR end_date >= CURDATE())", [$scope, $d['property_id'], $unitId, $blockId]);
        if ($existing + $share > 100.001) throw new ValidationError(['share_pct' => 'Shares would total ' . num($existing + $share, 1) . '% — end or reduce existing ownership first.']);
        $id = insert('prpt_ownerships', ['party_id' => (int)$d['party_id'], 'scope' => $scope, 'property_id' => (int)$d['property_id'], 'block_id' => $blockId, 'unit_id' => $unitId,
            'share_pct' => $share, 'acquisition' => input('acquisition', 'purchase'), 'start_date' => $d['start_date'], 'is_primary_contact' => (int)!!input('is_primary_contact'), 'title_ref' => nullable(input('title_ref')), 'notes' => nullable(input('notes'))]);
        q("UPDATE prpt_parties SET is_owner = 1 WHERE id = ?", [$d['party_id']]); portal_ensure_account((int)$d['party_id']);
        // attach new owner to active accounts so it appears in their portal
        $units = $scope === 'unit' ? [$unitId] : ($scope === 'block' ? col("SELECT id FROM prpt_units WHERE block_id = ?", [$blockId]) : col("SELECT id FROM prpt_units WHERE property_id = ?", [$d['property_id']]));
        foreach ($units as $u) if (($l = unit_active_lease((int)$u))) q("INSERT IGNORE INTO bln_lease_parties (lease_id, party_id, role) VALUES (?, ?, 'owner')", [$l['id'], $d['party_id']]);
        audit('property', 'create', 'ownership', $id, "Ownership $scope " . num($share, 1) . '%');
        json_ok([], 'Ownership recorded.');
    }],
    'end-ownership' => ['property.ownership.manage', function () {
        $id = (int)input_int('id'); $date = input_date('end_date', date('Y-m-d'));
        q("UPDATE prpt_ownerships SET end_date = ? WHERE id = ?", [$date, $id]);
        audit('property', 'end', 'ownership', $id, 'Ownership ended ' . $date);
        json_ok([], 'Ownership ended.');
    }],
    'property-account' => ['finance.accounts.manage', function () {
        $pid = (int)input_int('property_id'); $acc = (int)input_int('account_id');
        if (input('remove')) q("DELETE FROM fin_property_accounts WHERE property_id = ? AND account_id = ?", [$pid, $acc]);
        else {
            if (input('is_default')) q("UPDATE fin_property_accounts SET is_default = 0 WHERE property_id = ?", [$pid]);
            q("REPLACE INTO fin_property_accounts (property_id, account_id, account_reference_format, is_default) VALUES (?,?,?,?)", [$pid, $acc, input('account_reference_format', '{unit_no}'), (int)!!input('is_default')]);
        }
        audit('finance', 'map_account', 'property', $pid, 'Collection accounts updated');
        json_ok([], 'Collection accounts updated.');
    }],

    /* ---------- readings ---------- */
    'bulk-readings' => ['property.readings.capture', function () {
        $date = input_date('reading_date', date('Y-m-d')); $saved = 0; $errors = [];
        foreach ((array)($_POST['r'] ?? []) as $meterId => $val) {
            $val = trim((string)$val); if ($val === '') continue;
            try { reading_save((int)$meterId, (float)str_replace(',', '', $val), ['date' => $date, 'source' => 'bulk']); $saved++; }
            catch (ValidationError $e) { $errors["r[$meterId]"] = implode(' ', $e->errors); }
        }
        if ($errors && !$saved) throw new ValidationError($errors, 'No readings saved — fix the highlighted rows.');
        json_ok(['saved' => $saved, 'errors' => $errors], "$saved reading(s) saved" . ($errors ? ', ' . count($errors) . ' rejected' : '') . '.');
    }],
    'field-reading' => ['property.readings.capture', function () {
        $meterId = (int)input_int('meter_id'); $val = input_num('reading');
        if ($val === null) throw new ValidationError(['reading' => 'Enter the reading.']);
        $a = input_int('assignment_id') ? one("SELECT * FROM prpt_reading_assignments WHERE id = ?", [input_int('assignment_id')]) : null;
        if ($a && (int)$a['assigned_to'] !== staff_id() && !can('property.readings.approve')) json_fail('This assignment belongs to someone else.', 403);
        $photo = save_data_url((string)input('photo_data', ''), 'readings') ?? save_upload('photo', 'readings', ['image/jpeg', 'image/png', 'image/webp']);
        if ($a && $a['require_photo'] && !$photo) throw new ValidationError(['photo' => 'Take a photo of the meter as proof.']);
        $r = reading_save($meterId, $val, ['date' => input_date('reading_date', date('Y-m-d')), 'source' => 'field', 'photo_path' => $photo, 'assignment_id' => $a['id'] ?? null,
            'notes' => input('notes'), 'lat' => input_num('lat'), 'lng' => input_num('lng'), 'allow_rollover' => !!input('rollover')]);
        json_ok(['reading' => $r], $r['anomaly_flag'] ? 'Saved — flagged for review: ' . $r['anomaly_flag'] : 'Reading saved.');
    }],
    'skip-meter' => ['property.readings.capture', function () {
        q("UPDATE prpt_reading_assignment_items SET status = 'skipped', skip_reason = ? WHERE assignment_id = ? AND meter_id = ?", [mb_substr((string)input('reason', 'No access'), 0, 160), input_int('assignment_id'), input_int('meter_id')]);
        assignment_refresh((int)input_int('assignment_id'));
        json_ok([], 'Skipped.');
    }],
    'reading-review' => ['property.readings.approve', function () {
        $ids = array_map('intval', input_array('ids') ?: [input_int('id')]); $ok = input('decision') === 'approve';
        foreach ($ids as $id) {
            $r = one("SELECT * FROM prpt_meter_readings WHERE id = ?", [$id]); if (!$r || $r['status'] === 'billed') continue;
            if (!$ok && ($nv = input_num('corrected')) !== null && count($ids) === 1) {
                $cons = round(($nv - (float)$r['previous_reading']) * (float)val("SELECT multiplier FROM prpt_meters WHERE id = ?", [$r['meter_id']]), 3);
                update('prpt_meter_readings', ['current_reading' => $nv, 'consumption' => $cons, 'status' => 'approved', 'anomaly_flag' => null, 'notes' => 'Corrected from ' . $r['current_reading']], 'id = :id', ['id' => $id]);
                audit('property', 'correct', 'reading', $id, 'Corrected reading ' . $r['current_reading'] . ' → ' . $nv); continue;
            }
            q("UPDATE prpt_meter_readings SET status = ? WHERE id = ?", [$ok ? 'approved' : 'rejected', $id]);
            audit('property', $ok ? 'approve' : 'reject', 'reading', $id, ($ok ? 'Approved' : 'Rejected') . ' reading');
        }
        json_ok([], count($ids) . ' reading(s) ' . ($ok ? 'approved' : 'updated') . '.');
    }],
    'create-assignment' => ['property.readings.approve', function () {
        $d = validate($_POST, ['title' => 'required', 'property_id' => 'required', 'assigned_to' => 'required', 'due_date' => 'required|date']);
        $meters = array_map('intval', input_array('meters'));
        if (!$meters) { // all active meters matching filters
            $w = ['m.property_id = :p', "m.status = 'active'", 'm.unit_id IS NOT NULL']; $p = ['p' => (int)$d['property_id']];
            if ($bt = input_int('bill_type_id')) { $w[] = 'm.bill_type_id = :bt'; $p['bt'] = $bt; }
            if ($b = input_int('block_id')) { $w[] = 'u.block_id = :b'; $p['b'] = $b; }
            $meters = array_map('intval', col("SELECT m.id FROM prpt_meters m JOIN prpt_units u ON u.id = m.unit_id WHERE " . implode(' AND ', $w), $p));
        }
        $id = assignment_create($d + ['bill_type_id' => input_int('bill_type_id'), 'period_month' => input('period_month', date('Y-m')), 'require_photo' => input('require_photo', '1'), 'notes' => input('notes')], $meters);
        json_ok(['id' => $id], 'Assignment created and sent to the reader.');
    }],
    'cancel-assignment' => ['property.readings.approve', function () { q("UPDATE prpt_reading_assignments SET status = 'cancelled' WHERE id = ?", [input_int('id')]); json_ok([], 'Cancelled.'); }],

    /* ---------- templates & documents ---------- */
    'save-template' => ['property.templates.manage', function () {
        $d = validate($_POST, ['name' => 'required', 'code' => 'required', 'doc_type' => 'required', 'body_html' => 'required']);
        $row = ['code' => slugify($d['code']), 'name' => $d['name'], 'doc_type' => $d['doc_type'], 'usage_class' => input('usage_class', 'any'), 'body_html' => sanitize_html($d['body_html']), 'is_default' => (int)!!input('is_default')];
        if ($row['is_default']) q("UPDATE prpt_doc_templates SET is_default = 0 WHERE doc_type = ? AND usage_class = ?", [$row['doc_type'], $row['usage_class']]);
        if ($id = input_int('id')) update('prpt_doc_templates', $row, 'id = :id', ['id' => $id]); else $id = insert('prpt_doc_templates', $row);
        audit('property', 'save', 'template', $id, 'Saved template ' . $row['name']);
        json_ok(['id' => $id], 'Template saved.');
    }],
    'upload-document' => ['property.documents.manage', function () {
        $d = validate($_POST, ['title' => 'required', 'owner_type' => 'required|in:property,unit,party,lease,ticket,sale', 'owner_id' => 'required']);
        $path = save_upload('file', 'documents');
        if (!$path) throw new ValidationError(['file' => 'Choose a file.']);
        $party = input_int('party_id') ?? ($d['owner_type'] === 'party' ? (int)$d['owner_id'] : null);
        $prop = input_int('property_id') ?? ($d['owner_type'] === 'property' ? (int)$d['owner_id'] : ($d['owner_type'] === 'unit' ? (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$d['owner_id']]) : null));
        $id = insert('prpt_documents', ['uuid' => uuid4(), 'title' => $d['title'], 'doc_type' => input('doc_type', 'other'), 'owner_type' => $d['owner_type'], 'owner_id' => (int)$d['owner_id'],
            'property_id' => $prop, 'party_id' => $party, 'file_path' => $path, 'visible_in_portal' => (int)!!input('visible_in_portal'), 'created_by' => staff_id()]);
        if ($party && input('visible_in_portal')) portal_notify($party, 'document', 'New document shared: ' . $d['title'], null, 'fa-file-lines', portal_page('documents'));
        audit('property', 'upload', 'document', $id, 'Uploaded ' . $d['title']);
        json_ok([], 'Document uploaded.');
    }],
    'delete-document' => ['property.documents.manage', function () { q("DELETE FROM prpt_documents WHERE id = ?", [input_int('id')]); audit('property', 'delete', 'document', input_int('id'), 'Deleted document'); json_ok([], 'Deleted.'); }],
    'regen-agreement' => ['billing.leases.manage', function () { $id = lease_generate_agreement((int)input_int('lease_id')); json_ok(['redirect' => page('property/document', ['id' => $id])], 'Agreement regenerated.'); }],
    'sign-document' => ['property.documents.manage', function () {
        q("UPDATE prpt_documents SET signed_at = NOW(), signed_by_name = ? WHERE id = ?", [mb_substr((string)input('name', ''), 0, 160), input_int('id')]);
        audit('property', 'sign', 'document', input_int('id'), 'Marked as signed'); json_ok([], 'Marked as signed.');
    }],
];
