<?php
/** Billing module AJAX actions */
function _lease_or_fail(int $id): array { $l = one("SELECT * FROM bln_leases WHERE id = ?", [$id]); if (!$l) json_fail('Account not found.', 404); return $l; }
function _parse_lines(): array {
    $out = [];
    foreach ((array)($_POST['lines'] ?? []) as $ln) {
        if (empty($ln['bill_type_id']) && trim((string)($ln['description'] ?? '')) === '') continue;
        if (empty($ln['bill_type_id'])) throw new ValidationError(['lines' => 'Choose a bill type on every line.']);
        $qty = (float)str_replace(',', '', (string)($ln['quantity'] ?? 1)); $price = (float)str_replace(',', '', (string)($ln['unit_price'] ?? 0));
        if ($qty <= 0) throw new ValidationError(['lines' => 'Quantity must be greater than zero.']);
        $out[] = ['id' => !empty($ln['id']) ? (int)$ln['id'] : null, 'bill_type_id' => (int)$ln['bill_type_id'], 'description' => trim((string)($ln['description'] ?? '')) ?: bill_type((int)$ln['bill_type_id'])['name'], 'quantity' => $qty, 'unit_price' => $price];
    }
    if (!$out) throw new ValidationError(['lines' => 'Add at least one line.']);
    return $out;
}
return [
    /* ---------- accounts / leases ---------- */
    'unit-context' => ['billing.leases.manage', function () {
        $u = one("SELECT u.*, pr.name AS property, pr.due_day AS prop_due_day FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id WHERE u.id = ?", [input_int('unit_id')]);
        if (!$u) json_fail('Unit not found.', 404);
        $active = unit_active_lease((int)$u['id']);
        json_ok(['unit' => ['id' => (int)$u['id'], 'unit_no' => $u['unit_no'], 'property' => $u['property'], 'status' => $u['status'], 'purpose' => $u['purpose'], 'rent' => (float)$u['rent_amount'], 'service_charge' => (float)$u['service_charge'], 'deposit_months' => (float)$u['deposit_months'], 'usage' => $u['usage_class'], 'due_day' => (int)$u['prop_due_day']],
            'owners' => array_map(fn($o) => ['id' => $o['party_id'], 'name' => $o['display_name'], 'share' => $o['share_pct']], unit_owners((int)$u['id'])),
            'active_lease' => $active ? ['id' => (int)$active['id'], 'lease_no' => $active['lease_no']] : null]);
    }],
    'default-charges' => ['billing.leases.manage', function () {
        $unitId = (int)input_int('unit_id'); $occ = (string)input('occupancy_type', 'tenant_managed');
        $tenant = input_int('tenant_id'); $owner = input_int('owner_id') ?? unit_primary_owner_id($unitId);
        $rows = lease_default_charges($unitId, $occ, $tenant, $owner);
        $names = [];
        foreach ($rows as &$r) { $bt = bill_type((int)$r['bill_type_id']); $r['bill_type'] = $bt['name']; $r['bill_to_default'] = $bt['default_bill_to']; $r['unit_label'] = $bt['unit_label'];
            if ($r['bill_to_party_id'] && !isset($names[$r['bill_to_party_id']])) $names[$r['bill_to_party_id']] = val("SELECT display_name FROM prpt_parties WHERE id = ?", [$r['bill_to_party_id']]);
            $r['bill_to_name'] = $names[$r['bill_to_party_id']] ?? null; }
        json_ok(['charges' => $rows]);
    }],
    'create-lease' => ['billing.leases.manage', function () {
        $d = validate($_POST, ['unit_id' => 'required', 'occupancy_type' => 'required', 'start_date' => 'required|date']);
        $occ = $d['occupancy_type']; $parties = [];
        if (in_array($occ, ['owner_occupied', 'owner_vacant'], true)) {
            $owner = input_int('owner_id') ?? unit_primary_owner_id((int)$d['unit_id']);
            if (!$owner) throw new ValidationError(['owner_id' => 'This unit has no owner on record — add the ownership first or choose an owner.']);
            $parties[] = ['party_id' => $owner, 'role' => 'owner', 'is_primary' => 1];
        } elseif ($occ === 'anonymous') {
            $pid = input_int('tenant_id') ?: party_create(['party_type' => 'individual', 'first_name' => 'Occupant', 'last_name' => (string)val("SELECT unit_no FROM prpt_units WHERE id = ?", [$d['unit_id']]), 'is_tenant' => 1, 'notes' => 'Anonymous occupant — tenant details not known']);
            $parties[] = ['party_id' => $pid, 'role' => 'occupant', 'is_primary' => 1];
        } else {
            $t = input_int('tenant_id'); if (!$t) throw new ValidationError(['tenant_id' => 'Choose the tenant (or create them first).']);
            $parties[] = ['party_id' => $t, 'role' => 'tenant', 'is_primary' => 1];
            foreach (input_array('co_tenants') as $ct) if ((int)$ct && (int)$ct !== $t) $parties[] = ['party_id' => (int)$ct, 'role' => 'co_tenant', 'is_primary' => 0];
            if ($g = input_int('guarantor_id')) $parties[] = ['party_id' => $g, 'role' => 'guarantor', 'is_primary' => 0];
        }
        $charges = [];
        foreach ((array)($_POST['charges'] ?? []) as $c) {
            if (empty($c['on']) || empty($c['bill_type_id'])) continue;
            $charges[] = ['bill_type_id' => (int)$c['bill_type_id'], 'billing_method' => $c['billing_method'] ?? 'fixed', 'amount' => ($c['amount'] ?? '') === '' ? null : (float)$c['amount'],
                'unit_rate' => ($c['unit_rate'] ?? '') === '' ? null : (float)$c['unit_rate'], 'frequency' => $c['frequency'] ?? 'monthly', 'bill_to_party_id' => (int)($c['bill_to_party_id'] ?? 0) ?: null, 'split_pct' => (float)($c['split_pct'] ?? 100)];
        }
        if (!$charges) throw new ValidationError(['charges' => 'Choose at least one bill to follow on this account.']);
        $rent = 0.0; $rentBt = bill_type_by_code('RENT'); foreach ($charges as $c) if ((int)$c['bill_type_id'] === (int)$rentBt['id']) $rent = (float)$c['amount'];
        $lease = lease_create(['unit_id' => (int)$d['unit_id'], 'occupancy_type' => $occ, 'start_date' => $d['start_date'], 'end_date' => input_date('end_date'), 'rent_amount' => $rent,
            'billing_day' => input_int('billing_day', 1), 'due_day' => input_int('due_day', 5), 'deposit_amount' => input_num('deposit_amount', 0), 'escalation_pct' => input_num('escalation_pct'),
            'escalation_every_months' => input_int('escalation_every_months'), 'notice_period_days' => input_int('notice_period_days', 30), 'template_id' => input_int('template_id'),
            'auto_invoice' => input('auto_invoice', '1'), 'notes' => input('notes')], $parties, $charges);
        if (input('send_invites')) foreach ($parties as $p) { $pp = one("SELECT email FROM prpt_parties WHERE id = ?", [$p['party_id']]); if ($pp['email'] && val("SELECT status FROM prt_accounts WHERE party_id = ?", [$p['party_id']]) === 'pending') portal_send_activation((int)$p['party_id']); }
        if (input('bill_now') && $lease['auto_invoice']) { try { run_billing(substr($lease['start_date'], 0, 7) < date('Y-m') ? date('Y-m') : substr($lease['start_date'], 0, 7), (int)$lease['property_id'], [], false, 'manual'); } catch (Throwable $e) { error_log($e); } }
        json_ok(['redirect' => page('billing/lease', ['id' => $lease['id']])], 'Account ' . $lease['lease_no'] . ' opened.');
    }],
    'update-lease' => ['billing.leases.manage', function () {
        $l = _lease_or_fail((int)input_int('id'));
        $row = ['end_date' => input_date('end_date'), 'billing_day' => input_int('billing_day', 1), 'due_day' => input_int('due_day', 5), 'escalation_pct' => input_num('escalation_pct'),
            'escalation_every_months' => input_int('escalation_every_months'), 'notice_period_days' => input_int('notice_period_days', 30), 'auto_invoice' => (int)!!input('auto_invoice'), 'notes' => input('notes'), 'template_id' => input_int('template_id')];
        update('bln_leases', $row, 'id = :id', ['id' => $l['id']]);
        audit('billing', 'update', 'lease', $l['id'], 'Updated account ' . $l['lease_no'], array_intersect_key($l, $row), $row);
        json_ok([], 'Account updated.');
    }],
    'save-charge' => ['billing.leases.manage', function () {
        $l = _lease_or_fail((int)input_int('lease_id'));
        $d = validate($_POST, ['bill_type_id' => 'required', 'bill_to_party_id' => 'required', 'frequency' => 'required']);
        $bt = bill_type((int)$d['bill_type_id']);
        $row = ['bill_type_id' => (int)$d['bill_type_id'], 'billing_method' => $bt['billing_method'] === 'metered' ? 'metered' : (input('frequency') === 'one_off' ? 'one_off' : 'fixed'), 'amount' => input_num('amount'), 'unit_rate' => input_num('unit_rate'),
            'frequency' => $d['frequency'], 'bill_to_party_id' => (int)$d['bill_to_party_id'], 'split_pct' => input_num('split_pct', 100), 'start_date' => input_date('start_date', date('Y-m-d')), 'end_date' => input_date('end_date'), 'is_active' => (int)!!input('is_active', '1')];
        if ($row['billing_method'] !== 'metered' && !$row['amount']) throw new ValidationError(['amount' => 'Enter the amount.']);
        if ($id = input_int('id')) { $old = one("SELECT * FROM bln_lease_charges WHERE id = ? AND lease_id = ?", [$id, $l['id']]); update('bln_lease_charges', $row, 'id = :id', ['id' => $id]); audit('billing', 'update', 'lease_charge', $id, "Changed {$bt['name']} on {$l['lease_no']}", $old, $row); }
        else { $id = insert('bln_lease_charges', $row + ['lease_id' => $l['id'], 'unit_id' => $l['unit_id']]); audit('billing', 'create', 'lease_charge', $id, "Added {$bt['name']} to {$l['lease_no']}"); }
        if ($bt['code'] === 'RENT') q("UPDATE bln_leases SET rent_amount = ? WHERE id = ?", [(float)$row['amount'], $l['id']]);
        json_ok([], 'Bill saved.');
    }],
    'delete-charge' => ['billing.leases.manage', function () {
        $c = one("SELECT * FROM bln_lease_charges WHERE id = ?", [input_int('id')]); if (!$c) json_fail('Not found', 404);
        if (val("SELECT 1 FROM bln_invoice_lines WHERE lease_charge_id = ? LIMIT 1", [$c['id']])) { q("UPDATE bln_lease_charges SET is_active = 0, end_date = CURDATE() WHERE id = ?", [$c['id']]); $m = 'Bill stopped (it has invoice history).'; }
        else { q("DELETE FROM bln_lease_charges WHERE id = ?", [$c['id']]); $m = 'Bill removed.'; }
        audit('billing', 'delete', 'lease_charge', $c['id'], $m); json_ok([], $m);
    }],
    'add-lease-party' => ['billing.leases.manage', function () {
        $l = _lease_or_fail((int)input_int('lease_id')); $d = validate($_POST, ['party_id' => 'required', 'role' => 'required|in:tenant,co_tenant,owner,guarantor,occupant,payer']);
        if (val("SELECT 1 FROM bln_lease_parties WHERE lease_id = ? AND party_id = ? AND role = ?", [$l['id'], $d['party_id'], $d['role']])) json_fail('Already on this account.');
        insert('bln_lease_parties', ['lease_id' => $l['id'], 'party_id' => (int)$d['party_id'], 'role' => $d['role'], 'is_primary' => 0]);
        if (in_array($d['role'], ['tenant', 'co_tenant'], true)) q("UPDATE prpt_parties SET is_tenant = 1 WHERE id = ?", [$d['party_id']]);
        portal_ensure_account((int)$d['party_id']);
        audit('billing', 'add_party', 'lease', $l['id'], 'Added ' . $d['role'] . ' to ' . $l['lease_no']); json_ok([], 'Added.');
    }],
    'remove-lease-party' => ['billing.leases.manage', function () {
        $lp = one("SELECT * FROM bln_lease_parties WHERE id = ?", [input_int('id')]);
        if ($lp && $lp['is_primary']) json_fail('The primary party cannot be removed.');
        if ($lp && val("SELECT 1 FROM bln_lease_charges WHERE lease_id = ? AND bill_to_party_id = ? AND is_active = 1", [$lp['lease_id'], $lp['party_id']])) json_fail('This person is billed for active charges on the account — move those bills first.');
        q("DELETE FROM bln_lease_parties WHERE id = ?", [input_int('id')]); json_ok([], 'Removed.');
    }],
    'lease-notice' => ['billing.leases.manage', function () {
        $l = _lease_or_fail((int)input_int('id')); $date = input_date('end_date');
        if (!$date) throw new ValidationError(['end_date' => 'Choose the move-out date.']);
        update('bln_leases', ['status' => 'notice', 'end_date' => $date, 'termination_reason' => input('reason')], 'id = :id', ['id' => $l['id']]);
        foreach (col("SELECT party_id FROM bln_lease_parties WHERE lease_id = ?", [$l['id']]) as $pid) portal_notify((int)$pid, 'lease', 'Notice recorded on ' . $l['lease_no'], 'Move-out date ' . fdate($date), 'fa-door-open');
        audit('billing', 'notice', 'lease', $l['id'], "Notice on {$l['lease_no']} → " . $date); json_ok([], 'Notice recorded.');
    }],
    'end-lease' => ['billing.leases.manage', function () {
        $l = _lease_or_fail((int)input_int('id')); $date = input_date('end_date', date('Y-m-d'));
        lease_end((int)$l['id'], $date, (string)input('reason', 'Moved out'), input('terminate') ? 'terminated' : 'ended');
        json_ok([], 'Account closed — unit is now vacant.');
    }],
    'send-portal-invites' => ['property.portal.invite', function () {
        $n = 0; foreach (col("SELECT lp.party_id FROM bln_lease_parties lp JOIN prpt_parties p ON p.id = lp.party_id WHERE lp.lease_id = ? AND p.email IS NOT NULL", [input_int('id')]) as $pid) {
            portal_ensure_account((int)$pid); if (val("SELECT status FROM prt_accounts WHERE party_id = ?", [$pid]) === 'pending') { portal_send_activation((int)$pid); $n++; } }
        json_ok([], $n ? "$n activation link(s) sent." : 'Everyone on this account is already active.');
    }],

    /* ---------- invoices ---------- */
    'save-invoice' => ['billing.invoices.create', function () {
        $lines = _parse_lines();
        if ($id = input_int('id')) {
            if (!can('billing.invoices.edit')) json_fail('You cannot edit invoices.', 403);
            $inv = invoice_update($id, ['issue_date' => input_date('issue_date'), 'due_date' => input_date('due_date'), 'notes' => input('notes'), 'party_id' => input_int('party_id')], $lines);
        } else {
            $d = validate($_POST, ['party_id' => 'required', 'issue_date' => 'required|date', 'due_date' => 'required|date']);
            $unitId = input_int('unit_id'); $lease = $unitId ? unit_active_lease($unitId) : null;
            $propId = $unitId ? (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$unitId]) : input_int('property_id');
            if (!$propId) throw new ValidationError(['unit_id' => 'Choose the unit (or property) this invoice is for.']);
            $inv = invoice_create(['party_id' => (int)$d['party_id'], 'unit_id' => $unitId, 'property_id' => $propId, 'lease_id' => $lease['id'] ?? null, 'issue_date' => $d['issue_date'], 'due_date' => $d['due_date'],
                'period_month' => input('period_month') ?: null, 'source' => 'manual', 'notes' => input('notes'), 'status' => input('draft') ? 'draft' : 'issued'], array_map(fn($l) => array_diff_key($l, ['id' => 1]), $lines));
            audit('billing', 'create', 'invoice', $inv['id'], 'Manual invoice ' . $inv['invoice_no'] . ' ' . money($inv['total']));
            if (input('send') && $inv['status'] !== 'draft') invoice_send((int)$inv['id']);
        }
        json_ok(['redirect' => page('billing/invoice', ['id' => $inv['id']])], 'Invoice ' . $inv['invoice_no'] . ' saved.');
    }],
    'issue-invoice' => ['billing.invoices.create', function () {
        $id = (int)input_int('id'); q("UPDATE bln_invoices SET status = 'issued' WHERE id = ? AND status = 'draft'", [$id]); $inv = invoice_recalc($id); apply_open_credits($id);
        invoice_event($id, 'note', 'Draft issued'); json_ok([], 'Invoice issued.');
    }],
    'send-invoice' => ['billing.invoices.send', function () { foreach (array_map('intval', input_array('ids') ?: [input_int('id')]) as $id) invoice_send($id); json_ok([], 'Sent by ' . (count(input_array('ids')) > 1 ? 'email / SMS and portal.' : 'email and portal.')); }],
    'void-invoice' => ['billing.invoices.void', function () { invoice_void((int)input_int('id'), (string)input('reason', 'Voided')); json_ok([], 'Invoice voided.'); }],
    'delete-invoice' => ['billing.invoices.void', function () {
        $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [input_int('id')]); if (!$inv) json_fail('Not found', 404);
        if ((float)$inv['amount_paid'] > 0 || val("SELECT 1 FROM fin_allocations WHERE invoice_id = ? AND reversed_at IS NULL LIMIT 1", [$inv['id']])) json_fail('This invoice has payments — reverse them or void the invoice instead.');
        tx(function () use ($inv) {
            q("UPDATE prpt_meter_readings SET status = 'approved', invoice_line_id = NULL WHERE invoice_line_id IN (SELECT id FROM bln_invoice_lines WHERE invoice_id = ?)", [$inv['id']]);
            q("UPDATE bln_lease_charges c JOIN bln_invoice_lines l ON l.lease_charge_id = c.id SET c.last_billed_period = NULL WHERE l.invoice_id = ? AND c.last_billed_period = ?", [$inv['id'], $inv['period_month']]);
            q("DELETE FROM bln_invoice_events WHERE invoice_id = ?", [$inv['id']]); q("DELETE FROM bln_invoice_lines WHERE invoice_id = ?", [$inv['id']]); q("DELETE FROM bln_credit_notes WHERE invoice_id = ?", [$inv['id']]);
            q("UPDATE bln_deposits SET invoice_id = NULL WHERE invoice_id = ?", [$inv['id']]);
            q("DELETE FROM bln_invoices WHERE id = ?", [$inv['id']]);
        });
        audit('billing', 'delete', 'invoice', $inv['id'], 'Deleted invoice ' . $inv['invoice_no'] . ' (' . money($inv['total']) . ')', $inv);
        json_ok(['redirect' => page('billing/invoices')], 'Invoice deleted.');
    }],
    'credit-note' => ['billing.invoices.edit', function () { invoice_credit_note((int)input_int('id'), (float)input_num('amount', 0), (string)input('reason', 'Credit')); json_ok([], 'Credit note applied.'); }],
    'write-off' => ['billing.invoices.void', function () {
        $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [input_int('id')]);
        update('bln_invoices', ['status' => 'written_off', 'void_reason' => input('reason')], 'id = :id', ['id' => $inv['id']]);
        invoice_event((int)$inv['id'], 'written_off', 'Written off: ' . input('reason'), (float)$inv['balance'], 0); q("UPDATE bln_invoices SET balance = 0 WHERE id = ?", [$inv['id']]);
        audit('billing', 'write_off', 'invoice', $inv['id'], 'Wrote off ' . $inv['invoice_no'] . ' ' . money($inv['balance'])); json_ok([], 'Balance written off.');
    }],
    'invoice-note' => ['billing.invoices.view', function () { invoice_event((int)input_int('id'), 'note', mb_substr((string)input('note', ''), 0, 250)); json_ok([], 'Note added to the timeline.'); }],
    /** Payment history for an invoice / line / party+unit (clickable "amount paid" modal) */
    'paid-history' => ['billing.invoices.view', function () {
        $w = ['a.reversed_at IS NULL']; $p = [];
        if ($v = input_int('line_id')) { $w[] = 'a.invoice_line_id = :v'; $p['v'] = $v; }
        elseif ($v = input_int('invoice_id')) { $w[] = 'a.invoice_id = :v'; $p['v'] = $v; }
        else { $w[] = 'i.party_id = :pt'; $p['pt'] = input_int('party_id');
            if ($u = input_int('unit_id')) { $w[] = 'i.unit_id = :u'; $p['u'] = $u; }
            if ($b = input_int('bill_type_id')) { $w[] = 'a.bill_type_id = :b'; $p['b'] = $b; }
            if ($f = input_date('from')) { $w[] = 'i.issue_date >= :f'; $p['f'] = $f; }
            if ($t = input_date('to')) { $w[] = 'i.issue_date <= :t'; $p['t'] = $t; } }
        $rows = all("SELECT a.amount, a.mode, a.created_at, i.invoice_no, i.id AS invoice_id, l.description, bt.name AS bill_type, pm.receipt_no, pm.reference, pm.method, pm.paid_at, pm.id AS payment_id, pm.uuid AS payment_uuid, fa.name AS account, c.id AS credit_id
            FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN bln_invoice_lines l ON l.id = a.invoice_line_id JOIN cfg_bill_types bt ON bt.id = a.bill_type_id
            LEFT JOIN fin_payments pm ON pm.id = a.payment_id LEFT JOIN fin_accounts fa ON fa.id = pm.account_id LEFT JOIN fin_credits c ON c.id = a.credit_id
            WHERE " . implode(' AND ', $w) . " ORDER BY COALESCE(pm.paid_at, a.created_at) DESC LIMIT 300", $p);
        json_ok(['items' => array_map(fn($r) => $r + ['paid_label' => fdatetime($r['paid_at'] ?? $r['created_at']), 'amount_label' => money($r['amount']), 'payment_url' => $r['payment_uuid'] ? page('finance/consume', ['payment' => $r['payment_uuid']]) : null, 'invoice_url' => page('billing/invoice', ['id' => $r['invoice_id']])], $rows),
            'total' => money(array_sum(array_column($rows, 'amount')))]);
    }],

    /* ---------- billing runs ---------- */
    'run-preview' => ['billing.runs.execute', function () {
        $period = (string)input('period_month', date('Y-m')); if (!preg_match('/^\d{4}-\d{2}$/', $period)) throw new ValidationError(['period_month' => 'Choose a month.']);
        $rows = run_billing($period, input_int('property_id'), array_map('intval', input_array('bill_types')), true);
        json_ok(['rows' => $rows, 'count' => count($rows), 'total' => round2(array_sum(array_column($rows, 'total')))]);
    }],
    'run-execute' => ['billing.runs.execute', function () {
        $period = (string)input('period_month', date('Y-m'));
        $r = run_billing($period, input_int('property_id'), array_map('intval', input_array('bill_types')), false, 'manual');
        if (input('send') && $r['invoices']) foreach ($r['invoice_ids'] as $iid) { try { invoice_send((int)$iid); } catch (Throwable $e) { error_log($e); } }
        json_ok(['redirect' => page('billing/runs', ['run' => $r['run_id']])], $r['invoices'] ? "{$r['invoices']} invoices raised (" . money($r['total']) . ')' . (input('send') ? ' and sent.' : '.') : 'Nothing was due for that period.');
    }],
    'run-send' => ['billing.invoices.send', function () {
        $ids = col("SELECT id FROM bln_invoices WHERE run_id = ? AND sent_at IS NULL AND status NOT IN ('void','draft')", [input_int('run_id')]);
        foreach ($ids as $id) invoice_send((int)$id);
        json_ok([], count($ids) . ' invoice(s) sent.');
    }],

    /* ---------- deposits ---------- */
    'deposit-update' => ['billing.deposits.manage', function () {
        $d = one("SELECT d.*, l.lease_no FROM bln_deposits d JOIN bln_leases l ON l.id = d.lease_id WHERE d.id = ?", [input_int('id')]); if (!$d) json_fail('Not found', 404);
        $act = (string)input('do');
        if ($act === 'held') { update('bln_deposits', ['amount_held' => input_num('amount_held', (float)$d['amount_required']), 'held_by' => input('held_by', 'agency'), 'status' => 'held'], 'id = :id', ['id' => $d['id']]); }
        elseif ($act === 'refund') {
            $ded = (float)input_num('deductions', 0); $ref = round2((float)$d['amount_held'] - $ded);
            if ($ref < 0) throw new ValidationError(['deductions' => 'Deductions exceed the deposit held.']);
            update('bln_deposits', ['deductions' => $ded, 'refunded_amount' => $ref, 'refund_date' => input_date('refund_date', date('Y-m-d')), 'status' => $ded > 0 ? 'partially_refunded' : 'refunded', 'notes' => input('notes')], 'id = :id', ['id' => $d['id']]);
            if ($ref > 0 && input('record_expense')) {
                $l = one("SELECT * FROM bln_leases WHERE id = ?", [$d['lease_id']]); $cat = val("SELECT id FROM fin_expense_categories WHERE code = 'DEP_REFUND'") ?: val("SELECT id FROM fin_expense_categories ORDER BY id LIMIT 1");
                insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat, 'property_id' => $l['property_id'], 'unit_id' => $l['unit_id'], 'party_id' => $d['party_id'],
                    'description' => 'Deposit refund — ' . $d['lease_no'], 'amount' => $ref, 'expense_date' => date('Y-m-d'), 'chargeable_to' => 'tenant', 'status' => 'approved', 'created_by' => staff_id()]);
            }
            portal_notify((int)$d['party_id'], 'deposit', 'Deposit refund processed', money($ref) . ($ded > 0 ? ' (' . money($ded) . ' deducted)' : ''), 'fa-vault');
        } elseif ($act === 'forfeit') update('bln_deposits', ['status' => 'forfeited', 'notes' => input('notes')], 'id = :id', ['id' => $d['id']]);
        elseif ($act === 'apply') {
            // use the deposit to settle the tenant's final bills: becomes a credit consumed by open invoices
            $amt = (float)$d['amount_held']; $l = one("SELECT * FROM bln_leases WHERE id = ?", [$d['lease_id']]);
            $cid = insert('fin_credits', ['payment_id' => null, 'party_id' => $d['party_id'], 'lease_id' => $l['id'], 'unit_id' => $l['unit_id'], 'amount' => $amt, 'remaining' => $amt, 'notes' => 'Deposit applied to final bills', 'created_by' => staff_id()]);
            foreach (col("SELECT id FROM bln_invoices WHERE lease_id = ? AND party_id = ? AND balance > 0 AND status NOT IN ('void','written_off','draft') ORDER BY due_date", [$l['id'], $d['party_id']]) as $iid) apply_open_credits((int)$iid);
            update('bln_deposits', ['status' => 'applied', 'notes' => 'Applied to arrears'], 'id = :id', ['id' => $d['id']]);
        } else json_fail('Unknown action');
        audit('billing', 'deposit_' . $act, 'deposit', $d['id'], ucfirst($act) . ' deposit on ' . $d['lease_no']);
        json_ok([], 'Deposit updated.');
    }],
];
