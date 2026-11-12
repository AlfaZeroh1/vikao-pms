<?php
/** Finance module AJAX actions */
function _payment(int $id): array { $p = one("SELECT * FROM fin_payments WHERE id = ?", [$id]); if (!$p) json_fail('Payment not found.', 404); return $p; }
return [
    /* ---------- payments ---------- */
    'save-payment' => ['finance.payments.create', function () {
        $d = validate($_POST, ['account_id' => 'required', 'method' => 'required|in:mpesa,bank_transfer,card,cash,cheque,rtgs,eft,offset,other', 'amount' => 'required|numeric', 'paid_at' => 'required']);
        $paidAt = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)$d['paid_at'])));
        if ($paidAt > date('Y-m-d H:i:s', time() + 300)) throw new ValidationError(['paid_at' => 'Payment date cannot be in the future.']);
        if ($id = input_int('id')) {
            $old = _payment($id);
            if ($old['status'] === 'reversed') json_fail('This payment was reversed.');
            if ($old['source'] === 'gateway' && (float)$d['amount'] !== (float)$old['amount']) throw new ValidationError(['amount' => 'Gateway payments come from the bank / M-Pesa — the amount cannot be changed.']);
            $used = (float)$old['allocated_amount'] + (float)$old['credit_amount'];
            if ((float)$d['amount'] + 0.004 < $used) throw new ValidationError(['amount' => 'Already ' . money($used) . ' of this payment is consumed. Undo allocations first.']);
            $row = ['account_id' => (int)$d['account_id'], 'method' => $d['method'], 'reference' => nullable(input('reference')), 'amount' => round2((float)$d['amount']), 'paid_at' => $paidAt,
                'payer_name' => nullable(input('payer_name')), 'payer_phone' => nullable(input('payer_phone')), 'notes' => nullable(input('notes'))];
            if (!$old['party_id'] || !(float)$used) { $row['party_id'] = input_int('party_id'); $row['unit_id'] = input_int('unit_id'); if ($row['unit_id'] && ($l = unit_active_lease($row['unit_id']))) $row['lease_id'] = $l['id']; }
            update('fin_payments', $row, 'id = :id', ['id' => $id]); $pay = payment_refresh($id);
            audit('finance', 'update', 'payment', $id, 'Edited payment ' . $old['receipt_no'], $old, $row);
            json_ok(['redirect' => page('finance/consume', ['payment' => $pay['uuid']])], 'Payment updated.');
        }
        $unitId = input_int('unit_id'); $partyId = input_int('party_id'); $leaseId = input_int('lease_id');
        if ($iid = input_int('invoice_id')) { $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [$iid]); $partyId = $partyId ?: (int)$inv['party_id']; $unitId = $unitId ?: ($inv['unit_id'] ? (int)$inv['unit_id'] : null); $leaseId = $leaseId ?: ($inv['lease_id'] ? (int)$inv['lease_id'] : null); }
        $pay = payment_record(['account_id' => (int)$d['account_id'], 'method' => $d['method'], 'source' => 'manual', 'reference' => input('reference') ? strtoupper(trim((string)input('reference'))) : null, 'amount' => (float)$d['amount'],
            'paid_at' => $paidAt, 'party_id' => $partyId, 'unit_id' => $unitId, 'lease_id' => $leaseId, 'payer_name' => input('payer_name'), 'payer_phone' => input('payer_phone'), 'notes' => input('notes')]);
        $mode = (string)input('allocate', 'consume');
        if ($mode === 'auto' && $pay['party_id']) { $pay = payment_auto_allocate((int)$pay['id']); if (input('send_receipt')) _send_receipt((int)$pay['id']); json_ok(['redirect' => page('finance/consume', ['payment' => $pay['uuid']])], "Receipt {$pay['receipt_no']} recorded and allocated."); }
        if ($mode === 'invoice' && !empty($inv)) {
            $left = (float)$pay['amount']; $plan = [];
            foreach (invoice_lines_with_balance((int)$inv['id']) as $ln) { if ($left <= 0) break; $a = min($left, (float)$ln['balance']); if ($a > 0) { $plan[] = ['line_id' => (int)$ln['id'], 'amount' => round2($a)]; $left = round2($left - $a); } }
            $pay = payment_allocate((int)$pay['id'], $plan, $left > 0 ? ['amount' => $left, 'party_id' => $partyId, 'unit_id' => $unitId, 'lease_id' => $leaseId, 'notes' => 'Overpayment on ' . $inv['invoice_no']] : null);
            if (input('send_receipt')) _send_receipt((int)$pay['id']);
            json_ok(['redirect' => page('billing/invoice', ['id' => $inv['id']])], "Receipt {$pay['receipt_no']} applied to {$inv['invoice_no']}.");
        }
        if (input('send_receipt') && $pay['party_id']) _send_receipt((int)$pay['id']);
        json_ok(['redirect' => page('finance/consume', ['payment' => $pay['uuid']])], "Receipt {$pay['receipt_no']} recorded — now consume it.");
    }],
    'delete-payment' => ['finance.payments.reverse', function () {
        $p = _payment((int)input_int('id'));
        if ((float)$p['allocated_amount'] + (float)$p['credit_amount'] > 0) json_fail('Undo the allocations first (or reverse the payment).');
        if ($p['source'] === 'gateway') json_fail('Gateway payments cannot be deleted — reverse them instead so the audit trail stays complete.');
        q("DELETE FROM fin_payments WHERE id = ?", [$p['id']]);
        audit('finance', 'delete', 'payment', $p['id'], 'Deleted payment ' . $p['receipt_no'] . ' ' . money($p['amount']), $p);
        json_ok(['redirect' => page('finance/payments')], 'Payment deleted.');
    }],
    'reverse-payment' => ['finance.payments.reverse', function () { payment_reverse((int)input_int('id'), (string)input('reason', 'Reversed')); json_ok([], 'Payment reversed.'); }],
    'unallocate' => ['finance.allocate', function () { payment_unallocate((int)input_int('id')); json_ok([], 'Allocations cleared — the payment can be consumed again.'); }],
    'auto-allocate' => ['finance.allocate', function () { $p = payment_auto_allocate((int)input_int('id')); json_ok([], 'Allocated ' . money($p['allocated_amount']) . ((float)$p['credit_amount'] > 0 ? ', ' . money($p['credit_amount']) . ' kept as overpayment' : '') . '.'); }],
    'assign-payer' => ['finance.allocate', function () {
        $p = _payment((int)input_int('id')); $party = (int)input_int('party_id'); if (!$party) throw new ValidationError(['party_id' => 'Choose who paid.']);
        $unit = input_int('unit_id'); $lease = $unit ? unit_active_lease($unit) : null;
        update('fin_payments', ['party_id' => $party, 'unit_id' => $unit, 'lease_id' => $lease['id'] ?? null, 'property_id' => $unit ? (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$unit]) : $p['property_id']], 'id = :id', ['id' => $p['id']]);
        if ($p['gateway_txn_id']) q("UPDATE fin_gateway_transactions SET match_status = 'matched' WHERE id = ?", [$p['gateway_txn_id']]);
        audit('finance', 'assign', 'payment', $p['id'], 'Assigned ' . $p['receipt_no'] . ' to party #' . $party);
        json_ok([], 'Payer assigned.');
    }],
    /** Consume page: everything the payer owes, their credits and their accounts */
    'open-lines' => ['finance.allocate', function () {
        $party = (int)input_int('party_id'); if (!$party) json_ok(['lines' => [], 'credits' => [], 'accounts' => []]);
        $lines = open_lines_for($party, input_int('lease_id'), input_int('unit_id'));
        $accounts = all("SELECT DISTINCT l.id AS lease_id, l.lease_no, u.id AS unit_id, u.unit_no, pr.name AS property FROM bln_lease_parties lp JOIN bln_leases l ON l.id = lp.lease_id JOIN prpt_units u ON u.id = l.unit_id JOIN prpt_properties pr ON pr.id = l.property_id WHERE lp.party_id = ? ORDER BY l.status IN ('active','notice') DESC, u.unit_no", [$party]);
        $credits = all("SELECT c.*, u.unit_no, bt.name AS bt FROM fin_credits c LEFT JOIN prpt_units u ON u.id = c.unit_id LEFT JOIN cfg_bill_types bt ON bt.id = c.bill_type_id WHERE c.party_id = ? AND c.status = 'open' AND c.remaining > 0", [$party]);
        $party = one("SELECT id, display_name, phone, email, party_no FROM prpt_parties WHERE id = ?", [$party]);
        json_ok(['party' => $party, 'lines' => array_map(fn($l) => ['id' => (int)$l['id'], 'invoice_id' => (int)$l['invoice_id'], 'invoice_no' => $l['invoice_no'], 'invoice_url' => page('billing/invoice', ['id' => $l['invoice_id']]),
            'bill_type' => $l['bill_type'], 'bill_type_id' => (int)$l['bill_type_id'], 'color' => $l['color_hex'], 'description' => $l['description'], 'unit_no' => $l['unit_no'], 'unit_id' => $l['unit_id'] ? (int)$l['unit_id'] : null, 'lease_id' => $l['lease_id'] ? (int)$l['lease_id'] : null,
            'property' => $l['property_name'], 'period' => $l['period_month'] ? month_label($l['period_month']) : null, 'due_date' => $l['due_date'], 'due_label' => fdate($l['due_date']), 'overdue' => $l['due_date'] < date('Y-m-d'),
            'total' => round2((float)$l['amount'] + (float)$l['tax_amount']), 'paid' => (float)$l['amount_paid'], 'balance' => round2((float)$l['balance'])], $lines),
            'credits' => $credits, 'accounts' => $accounts, 'bill_types' => array_values(array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name']], bill_types()))]);
    }],
    'allocate' => ['finance.allocate', function () {
        $pid = (int)input_int('payment_id'); $lines = [];
        foreach ((array)($_POST['lines'] ?? []) as $lid => $amt) { $amt = round2((float)str_replace(',', '', (string)$amt)); if ($amt > 0) $lines[] = ['line_id' => (int)$lid, 'amount' => $amt]; }
        $credit = null;
        if (($ca = round2((float)input_num('credit_amount', 0))) > 0) $credit = ['amount' => $ca, 'party_id' => input_int('credit_party_id') ?? input_int('party_id'), 'unit_id' => input_int('credit_unit_id'), 'lease_id' => input_int('credit_lease_id'), 'bill_type_id' => input_int('credit_bill_type_id'), 'notes' => input('credit_notes') ?: 'Overpayment — consumed next cycle'];
        $pay = _payment($pid);
        if (!$pay['party_id'] && input_int('party_id')) update('fin_payments', ['party_id' => input_int('party_id')], 'id = :id', ['id' => $pid]);
        $pay = payment_allocate($pid, $lines, $credit, 'manual');
        if (input('send_receipt')) _send_receipt($pid);
        json_ok(['payment' => $pay], 'Consumed: ' . money($pay['allocated_amount']) . ' to bills' . ((float)$pay['credit_amount'] > 0 ? ' · ' . money($pay['credit_amount']) . ' held as overpayment' : '') . ((float)$pay['unallocated_amount'] > 0 ? ' · ' . money($pay['unallocated_amount']) . ' still unallocated' : '') . '.');
    }],
    'cancel-credit' => ['finance.allocate', function () {
        $c = one("SELECT * FROM fin_credits WHERE id = ?", [input_int('id')]); if (!$c || $c['status'] !== 'open') json_fail('Credit is not open.');
        if ((float)$c['remaining'] < (float)$c['amount']) json_fail('Part of this credit was already consumed — it cannot be cancelled.');
        q("UPDATE fin_credits SET status = 'cancelled', remaining = 0 WHERE id = ?", [$c['id']]); if ($c['payment_id']) payment_refresh((int)$c['payment_id']);
        audit('finance', 'cancel', 'credit', $c['id'], 'Cancelled overpayment credit ' . money($c['amount'])); json_ok([], 'Credit released back to the payment.');
    }],
    'send-receipt' => ['finance.payments.view', function () { _send_receipt((int)input_int('id')); json_ok([], 'Receipt sent.'); }],
    'search-payments' => ['finance.payments.view', function () {
        $q = '%' . trim((string)input('q', '')) . '%';
        $rows = all("SELECT pm.uuid, pm.receipt_no, pm.reference, pm.amount, pm.unallocated_amount, pm.paid_at, pm.payer_name, p.display_name FROM fin_payments pm LEFT JOIN prpt_parties p ON p.id = pm.party_id
            WHERE pm.status IN ('unallocated','partially_allocated') AND (pm.receipt_no LIKE :q OR pm.reference LIKE :q OR pm.payer_name LIKE :q OR pm.payer_phone LIKE :q OR p.display_name LIKE :q) ORDER BY pm.paid_at DESC LIMIT 15", ['q' => $q]);
        json_ok(['items' => array_map(fn($r) => ['id' => $r['uuid'], 'label' => $r['receipt_no'] . ' · ' . money($r['amount']), 'sub' => trim(($r['display_name'] ?: $r['payer_name']) . ' · ' . $r['reference'] . ' · ' . money($r['unallocated_amount']) . ' free')], $rows)]);
    }],

    /* ---------- gateways ---------- */
    'simulate' => ['finance.gateways.view', function () {
        $prov = (string)input('provider'); $amt = (float)input_num('amount', 0); $ref = strtoupper(trim((string)input('bill_ref', ''))); $phone = (string)input('phone', '0712345678'); $name = (string)input('name', 'TEST PAYER');
        if ($amt <= 0) throw new ValidationError(['amount' => 'Enter an amount.']);
        $res = match ($prov) {
            'mpesa' => mpesa_simulate_c2b($amt, $ref, $phone, $name),
            'coop' => coop_ingest(['TransactionId' => 'FT' . date('ymd') . random_code(8, '0123456789'), 'Amount' => $amt, 'BillRef' => $ref, 'Narration' => 'Deposit ' . $ref, 'CustomerName' => $name, 'CustomerMobileNumber' => $phone, 'TransactionDate' => date('c'), '_simulated' => 1]),
            'stripe' => ingest_gateway_txn('stripe', ['transaction_id' => 'pi_sim_' . strtolower(random_code(14, 'abcdefghijklmnopqrstuvwxyz0123456789')), 'transaction_type' => 'payment_intent.succeeded', 'amount' => $amt, 'payer_name' => $name, 'bill_ref' => $ref, 'simulated' => 1,
                'account_id' => (int)val("SELECT id FROM fin_accounts WHERE integration_code = 'stripe_vikao' LIMIT 1") ?: null]),
            default => json_fail('Unknown provider'),
        };
        $m = !empty($res['duplicate']) ? 'Duplicate transaction ignored.' : (!empty($res['matched']) ? 'Payment received, matched and auto-allocated.' : 'Payment received but not matched — assign it on the Payments page.');
        json_ok(['result' => $res], $m);
    }],
    'coop-pull' => ['finance.gateways.view', function () { $r = coop_pull_transactions((int)input_int('days', 2)); json_ok($r, !empty($r['simulated']) ? 'Co-op integration is in simulate mode — use "Simulate" to test.' : ($r['ingested'] . ' new transaction(s) pulled.')); }],
    'mpesa-register' => ['config.integrations.manage', function () { $r = mpesa_register_urls(); json_ok($r, !empty($r['simulated']) ? 'Simulate mode — nothing registered.' : 'Confirmation & validation URLs registered with Safaricom.'); }],
    'stk-push' => ['finance.payments.create', function () {
        $inv = one("SELECT i.*, u.unit_no FROM bln_invoices i LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE i.id = ?", [input_int('invoice_id')]);
        $phone = (string)input('phone'); $amt = (float)input_num('amount', (float)$inv['balance']);
        $r = mpesa_stk_push($phone, $amt, $inv['unit_no'] ?: $inv['invoice_no'], 'Inv ' . $inv['invoice_no']);
        if (!empty($r['simulated'])) mpesa_simulate_c2b($amt, $inv['unit_no'] ?: $inv['invoice_no'], $phone, (string)val("SELECT display_name FROM prpt_parties WHERE id = ?", [$inv['party_id']]));
        invoice_event((int)$inv['id'], 'reminder', 'M-Pesa STK push sent to ' . $phone . ' for ' . money($amt));
        json_ok($r, !empty($r['simulated']) ? 'Simulated: STK push approved and payment received.' : 'Payment prompt sent to ' . $phone . '.');
    }],
    'txn-ignore' => ['finance.allocate', function () { q("UPDATE fin_gateway_transactions SET match_status = 'ignored' WHERE id = ?", [input_int('id')]); json_ok([], 'Marked as ignored.'); }],

    /* ---------- expenses ---------- */
    'save-expense' => ['finance.expenses.manage', function () {
        $d = validate($_POST, ['category_id' => 'required', 'description' => 'required', 'amount' => 'required|numeric', 'expense_date' => 'required|date', 'chargeable_to' => 'required|in:agency,owner,property_fund,tenant']);
        $unit = input_int('unit_id'); $prop = input_int('property_id') ?: ($unit ? (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$unit]) : null);
        $tk = input_int('ticket_id'); if ($tk && !$prop) { $t = one("SELECT property_id, unit_id FROM crm_tickets WHERE id = ?", [$tk]); $prop = $t['property_id']; $unit = $unit ?: $t['unit_id']; }
        $row = ['category_id' => (int)$d['category_id'], 'vendor_id' => input_int('vendor_id'), 'expense_date' => $d['expense_date'], 'description' => $d['description'], 'amount' => round2((float)$d['amount']), 'tax_amount' => (float)input_num('tax_amount', 0),
            'property_id' => $prop, 'unit_id' => $unit, 'party_id' => input_int('party_id'), 'ticket_id' => $tk, 'booking_id' => input_int('booking_id'), 'chargeable_to' => $d['chargeable_to'],
            'paid_from_account_id' => input_int('paid_from_account_id'), 'payment_ref' => nullable(input('payment_ref')), 'status' => input('status', 'approved')];
        if ($f = save_upload('receipt', 'expenses')) $row['receipt_path'] = $f;
        if ($id = input_int('id')) { $old = one("SELECT * FROM fin_expenses WHERE id = ?", [$id]); if ($old['remittance_id'] && (float)$old['amount'] !== $row['amount']) json_fail('Already deducted in a remittance — amount is locked.'); update('fin_expenses', $row, 'id = :id', ['id' => $id]); audit('finance', 'update', 'expense', $id, 'Edited expense ' . $old['expense_no'], $old, $row); }
        else { $id = insert('fin_expenses', $row + ['expense_no' => next_no('expense'), 'created_by' => staff_id(), 'approved_by' => $row['status'] !== 'draft' ? staff_id() : null]); audit('finance', 'create', 'expense', $id, 'Expense ' . money($row['amount']) . ' — ' . $row['description']); }
        if ($tk) { q("UPDATE crm_tickets SET cost_actual = (SELECT COALESCE(SUM(amount),0) FROM fin_expenses WHERE ticket_id = ? AND status <> 'rejected') WHERE id = ?", [$tk, $tk]); }
        if ($d['chargeable_to'] === 'tenant' && input('recharge') && $unit && ($l = unit_active_lease($unit))) {
            $bt = bill_type_by_code('RECHARGE') ?: bill_type_by_code('OTHER') ?: array_values(bill_types())[0];
            $inv = invoice_create(['lease_id' => $l['id'], 'property_id' => $prop, 'unit_id' => $unit, 'party_id' => lease_primary_payer((int)$l['id']), 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+7 days')), 'source' => 'manual', 'notes' => 'Recharge of expense'],
                [['bill_type_id' => (int)$bt['id'], 'description' => 'Recharge: ' . $d['description'], 'quantity' => 1, 'unit_price' => $row['amount'] + $row['tax_amount']]]);
            json_ok(['redirect' => page('billing/invoice', ['id' => $inv['id']])], 'Expense saved and recharged to the tenant on ' . $inv['invoice_no'] . '.');
        }
        json_ok(['id' => $id], 'Expense saved.');
    }],
    'expense-status' => ['finance.expenses.manage', function () {
        $e = one("SELECT * FROM fin_expenses WHERE id = ?", [input_int('id')]); $s = (string)input('status');
        if (!in_array($s, ['approved', 'paid', 'rejected'], true)) json_fail('Bad status');
        if ($e['remittance_id'] && $s === 'rejected') json_fail('Already deducted in a remittance.');
        update('fin_expenses', ['status' => $s, 'approved_by' => staff_id(), 'payment_ref' => input('payment_ref') ?: $e['payment_ref']], 'id = :id', ['id' => $e['id']]);
        audit('finance', $s, 'expense', $e['id'], ucfirst($s) . ' expense ' . $e['expense_no']); json_ok([], 'Expense ' . $s . '.');
    }],
    'delete-expense' => ['finance.expenses.manage', function () {
        $e = one("SELECT * FROM fin_expenses WHERE id = ?", [input_int('id')]); if ($e['remittance_id']) json_fail('Already deducted in a remittance.');
        q("DELETE FROM fin_expenses WHERE id = ?", [$e['id']]); audit('finance', 'delete', 'expense', $e['id'], 'Deleted expense ' . $e['expense_no'], $e); json_ok([], 'Deleted.');
    }],

    /* ---------- remittances ---------- */
    'remittance-preview' => ['finance.remittances.manage', function () {
        $pos = landlord_position((int)input_int('party_id'), (int)input_int('property_id'), (string)input_date('from'), (string)input_date('to'));
        json_ok(['pos' => $pos]);
    }],
    'create-remittance' => ['finance.remittances.manage', function () {
        $d = validate($_POST, ['property_id' => 'required', 'from' => 'required|date', 'to' => 'required|date']);
        $owners = input_int('party_id') ? [(int)input_int('party_id')] : array_map('intval', col("SELECT DISTINCT o.party_id FROM prpt_ownerships o LEFT JOIN prpt_units u ON u.id = o.unit_id LEFT JOIN prpt_blocks b ON b.id = o.block_id
            WHERE (o.property_id = :p OR u.property_id = :p OR b.property_id = :p) AND (o.end_date IS NULL OR o.end_date >= :f)", ['p' => (int)$d['property_id'], 'f' => $d['from']]));
        $made = 0; $skipped = [];
        foreach ($owners as $o) {
            if (mgmt_agreement_for($o, (int)$d['property_id']) === null && !input('include_no_agreement')) { $skipped[] = $o; continue; }
            try { $r = remittance_create($o, (int)$d['property_id'], $d['from'], $d['to']); if ((float)$r['net_payable'] == 0.0 && (float)$r['gross_collected'] == 0.0) { q("DELETE FROM fin_remittances WHERE id = ?", [$r['id']]); continue; } $made++; }
            catch (RuntimeException $e) { $skipped[] = $o; }
        }
        json_ok(['redirect' => page('finance/remittances', ['status' => 'draft'])], "$made remittance(s) prepared" . ($skipped ? ', ' . count($skipped) . ' skipped (already done, or self-managing with no agreement)' : '') . '.');
    }],
    'remittance-status' => ['finance.remittances.manage', function () {
        $r = one("SELECT * FROM fin_remittances WHERE id = ?", [input_int('id')]); $s = (string)input('status');
        if (in_array($s, ['approved', 'paid'], true) && !can('finance.remittances.approve')) json_fail('You cannot approve or pay remittances.', 403);
        $row = ['status' => $s];
        if ($s === 'approved') $row['approved_by'] = staff_id();
        if ($s === 'paid') { $row += ['paid_at' => date('Y-m-d H:i:s'), 'payment_ref' => nullable(input('payment_ref')), 'paid_from_account_id' => input_int('paid_from_account_id')]; }
        if ($s === 'cancelled') q("UPDATE fin_expenses SET remittance_id = NULL WHERE remittance_id = ?", [$r['id']]);
        if ($s === 'adjust') { $adj = (float)input_num('adjustments', 0); update('fin_remittances', ['adjustments' => $adj, 'net_payable' => round2((float)$r['gross_collected'] - (float)$r['mgmt_fee'] - (float)$r['mgmt_fee_vat'] - (float)$r['expenses'] - (float)$r['withholding_tax'] + $adj)], 'id = :id', ['id' => $r['id']]); json_ok([], 'Adjusted.'); }
        update('fin_remittances', $row, 'id = :id', ['id' => $r['id']]);
        if ($s === 'paid') {
            $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$r['party_id']]);
            try { send_templated('remittance_paid', $party, ['remittance_no' => $r['remittance_no'], 'amount' => $r['net_payable'], 'period' => fdate($r['period_start']) . ' – ' . fdate($r['period_end']), 'property_name' => val("SELECT name FROM prpt_properties WHERE id = ?", [$r['property_id']]), 'payment_ref' => $row['payment_ref'] ?? ''], null, 'remittance', (int)$r['id']); } catch (Throwable $e) { error_log($e); }
            portal_notify((int)$r['party_id'], 'remittance', 'Remittance paid — ' . money($r['net_payable']), $r['remittance_no'], 'fa-hand-holding-dollar', portal_page('revenue'));
        }
        audit('finance', $s, 'remittance', $r['id'], ucfirst($s) . ' remittance ' . $r['remittance_no']);
        json_ok([], 'Remittance ' . $s . '.');
    }],

    /* ---------- QuickBooks ---------- */
    'qb-mark' => ['finance.qb.export', function () { json_ok([], 'Noted.'); }],
];

function _send_receipt(int $paymentId): void {
    $p = one("SELECT pm.*, fa.name AS account FROM fin_payments pm LEFT JOIN fin_accounts fa ON fa.id = pm.account_id WHERE pm.id = ?", [$paymentId]);
    if (!$p || !$p['party_id']) return;
    $party = one("SELECT * FROM prpt_parties WHERE id = ?", [$p['party_id']]);
    $alloc = all("SELECT a.amount, i.invoice_no, bt.name FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN cfg_bill_types bt ON bt.id = a.bill_type_id WHERE a.payment_id = ? AND a.reversed_at IS NULL", [$paymentId]);
    $rows = ''; foreach ($alloc as $a) $rows .= '<tr><td style="padding:4px 0">' . e($a['invoice_no'] . ' · ' . $a['name']) . '</td><td style="text-align:right">' . money($a['amount']) . '</td></tr>';
    if ((float)$p['credit_amount'] > 0) $rows .= '<tr><td style="padding:4px 0">Held as credit for your next bills</td><td style="text-align:right">' . money($p['credit_amount']) . '</td></tr>';
    send_templated('payment_receipt', $party, ['receipt_no' => $p['receipt_no'], 'amount' => $p['amount'], 'reference' => $p['reference'] ?: $p['method'], 'paid_at' => fdatetime($p['paid_at']),
        'allocation_table' => '<table width="100%" style="font-size:14px">' . $rows . '</table>', 'balance' => party_balance((int)$p['party_id'])], null, 'payment', $paymentId);
}
