<?php
/**
 * Payments & allocation engine ("consume"): record payments, allocate to invoice lines
 * (exact / partial / full arrears), park overpayments as credits earmarked for a unit + bill,
 * auto-consume credits on new invoices, reversals. Every movement writes an invoice event.
 */

function payment_record(array $d): array {
    $amount = round2((float)$d['amount']);
    if ($amount <= 0) throw new ValidationError(['amount' => 'Amount must be greater than zero.']);
    if (!empty($d['reference']) && val("SELECT 1 FROM fin_payments WHERE account_id = ? AND reference = ?", [(int)$d['account_id'], $d['reference']]))
        throw new ValidationError(['reference' => 'A payment with this reference already exists on that account.']);
    $unitId = nullable($d['unit_id'] ?? null); $leaseId = nullable($d['lease_id'] ?? null); $propertyId = nullable($d['property_id'] ?? null);
    if ($unitId && !$propertyId) $propertyId = (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$unitId]);
    if ($unitId && !$leaseId && ($l = unit_active_lease((int)$unitId))) $leaseId = (int)$l['id'];
    $id = insert('fin_payments', [
        'uuid' => uuid4(), 'receipt_no' => next_no('receipt'), 'account_id' => (int)$d['account_id'], 'method' => $d['method'], 'source' => $d['source'] ?? 'manual',
        'reference' => nullable($d['reference'] ?? null), 'party_id' => nullable($d['party_id'] ?? null), 'property_id' => $propertyId, 'unit_id' => $unitId, 'lease_id' => $leaseId,
        'payer_name' => nullable($d['payer_name'] ?? null), 'payer_phone' => nullable($d['payer_phone'] ?? null), 'amount' => $amount, 'unallocated_amount' => $amount,
        'paid_at' => $d['paid_at'] ?? date('Y-m-d H:i:s'), 'gateway_txn_id' => nullable($d['gateway_txn_id'] ?? null), 'notes' => nullable($d['notes'] ?? null), 'created_by' => staff_id(),
    ]);
    $pay = one("SELECT * FROM fin_payments WHERE id = ?", [$id]);
    audit('finance', 'create', 'payment', $id, "Payment {$pay['receipt_no']} " . money($amount) . ($pay['reference'] ? " ref {$pay['reference']}" : ''));
    if ($pay['party_id']) portal_notify((int)$pay['party_id'], 'payment', 'Payment received — ' . money($amount), 'Receipt ' . $pay['receipt_no'] . ($pay['reference'] ? ' · ' . $pay['reference'] : ''), 'fa-circle-check', portal_page('payments'));
    return $pay;
}

function payment_refresh(int $paymentId): array {
    $p = one("SELECT * FROM fin_payments WHERE id = ?", [$paymentId]);
    $alloc = (float)val("SELECT COALESCE(SUM(amount),0) FROM fin_allocations WHERE payment_id = ? AND reversed_at IS NULL", [$paymentId]);
    $credit = (float)val("SELECT COALESCE(SUM(amount),0) FROM fin_credits WHERE payment_id = ? AND status <> 'cancelled'", [$paymentId]);
    $un = round2((float)$p['amount'] - $alloc - $credit);
    $status = $p['status'] === 'reversed' ? 'reversed' : ($un <= 0.004 ? 'allocated' : ($alloc + $credit > 0 ? 'partially_allocated' : 'unallocated'));
    update('fin_payments', ['allocated_amount' => round2($alloc), 'credit_amount' => round2($credit), 'unallocated_amount' => max(0, $un), 'status' => $status], 'id = :id', ['id' => $paymentId]);
    return one("SELECT * FROM fin_payments WHERE id = ?", [$paymentId]);
}

/** Apply $amount to one invoice line (from a payment or a credit). Returns amount applied. */
function _apply_to_line(array $line, float $amount, ?int $paymentId, ?int $creditId, string $mode): float {
    $bal = round2((float)$line['amount'] + (float)$line['tax_amount'] - (float)$line['amount_paid']);
    $amount = round2(min($amount, $bal));
    if ($amount <= 0) return 0;
    insert('fin_allocations', ['payment_id' => $paymentId, 'credit_id' => $creditId, 'invoice_id' => (int)$line['invoice_id'], 'invoice_line_id' => (int)$line['id'],
        'bill_type_id' => (int)$line['bill_type_id'], 'amount' => $amount, 'allocated_by' => staff_id(), 'mode' => $mode]);
    q("UPDATE bln_invoice_lines SET amount_paid = amount_paid + ? WHERE id = ?", [$amount, $line['id']]);
    return $amount;
}

/**
 * Consume a payment.
 * $lines:  [['line_id'=>int, 'amount'=>float], ...]  (amount = what to pay on that bill line)
 * $credit: null | ['amount'=>float, 'party_id'=>int, 'lease_id'=>?, 'unit_id'=>?, 'bill_type_id'=>?, 'notes'=>?]  → overpayment parked for next cycle
 */
function payment_allocate(int $paymentId, array $lines, ?array $credit = null, string $mode = 'manual'): array {
    return tx(function () use ($paymentId, $lines, $credit, $mode) {
        $pay = one("SELECT * FROM fin_payments WHERE id = ? FOR UPDATE", [$paymentId]);
        if (!$pay || $pay['status'] === 'reversed') throw new RuntimeException('Payment not found or reversed.');
        $available = (float)$pay['unallocated_amount'];
        $want = round2(array_sum(array_map(fn($l) => (float)$l['amount'], $lines)) + (float)($credit['amount'] ?? 0));
        if ($want <= 0) throw new ValidationError(['allocations' => 'Nothing to allocate.']);
        if ($want > $available + 0.004) throw new ValidationError(['allocations' => 'You are allocating ' . money($want) . ' but only ' . money($available) . ' is available on this payment.']);
        $touched = [];
        foreach ($lines as $l) {
            $amt = round2((float)$l['amount']); if ($amt <= 0) continue;
            $line = one("SELECT l.*, i.status AS inv_status FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id WHERE l.id = ? FOR UPDATE", [(int)$l['line_id']]);
            if (!$line || in_array($line['inv_status'], ['void', 'written_off'], true)) throw new ValidationError(['allocations' => 'An invoice line is no longer payable.']);
            $bal = round2((float)$line['amount'] + (float)$line['tax_amount'] - (float)$line['amount_paid']);
            if ($amt > $bal + 0.004) throw new ValidationError(['allocations' => "\"{$line['description']}\" only has " . money($bal) . ' outstanding. Put the extra into overpayment.']);
            _apply_to_line($line, $amt, $paymentId, null, $mode);
            $touched[(int)$line['invoice_id']] = ($touched[(int)$line['invoice_id']] ?? 0) + $amt;
        }
        foreach ($touched as $invId => $amt) {
            $inv = invoice_recalc($invId);
            invoice_event($invId, 'payment', "Payment {$pay['receipt_no']}" . ($pay['reference'] ? " ({$pay['reference']})" : '') . ' applied', $amt, (float)$inv['balance']);
        }
        if ($credit && (float)$credit['amount'] > 0) {
            $partyId = (int)($credit['party_id'] ?? $pay['party_id']);
            if (!$partyId) throw new ValidationError(['credit' => 'Choose whose account the overpayment belongs to.']);
            insert('fin_credits', ['payment_id' => $paymentId, 'party_id' => $partyId, 'lease_id' => nullable($credit['lease_id'] ?? null), 'unit_id' => nullable($credit['unit_id'] ?? null),
                'bill_type_id' => nullable($credit['bill_type_id'] ?? null), 'amount' => round2((float)$credit['amount']), 'remaining' => round2((float)$credit['amount']),
                'notes' => nullable($credit['notes'] ?? null), 'created_by' => staff_id()]);
        }
        if (!$pay['party_id'] && $touched) { // learn payer from the first invoice
            $first = one("SELECT party_id, unit_id, property_id, lease_id FROM bln_invoices WHERE id = ?", [array_key_first($touched)]);
            update('fin_payments', ['party_id' => $first['party_id'], 'unit_id' => $pay['unit_id'] ?? $first['unit_id'], 'property_id' => $pay['property_id'] ?? $first['property_id'], 'lease_id' => $pay['lease_id'] ?? $first['lease_id']], 'id = :id', ['id' => $paymentId]);
        }
        $pay = payment_refresh($paymentId);
        audit('finance', 'allocate', 'payment', $paymentId, "Allocated {$pay['receipt_no']}: " . money($pay['allocated_amount']) . ' to bills' . ((float)$pay['credit_amount'] > 0 ? ', ' . money($pay['credit_amount']) . ' as overpayment' : ''));
        return $pay;
    });
}

/** Open bill lines for a party (optionally a lease/unit), oldest first then by bill priority. */
function open_lines_for(int $partyId, ?int $leaseId = null, ?int $unitId = null): array {
    $w = ['i.party_id = :p', "i.status NOT IN ('void','written_off','draft')", '(l.amount + l.tax_amount - l.amount_paid) > 0.004']; $prm = ['p' => $partyId];
    if ($leaseId) { $w[] = 'i.lease_id = :l'; $prm['l'] = $leaseId; }
    if ($unitId) { $w[] = 'i.unit_id = :u'; $prm['u'] = $unitId; }
    return all("SELECT l.*, (l.amount + l.tax_amount - l.amount_paid) AS balance, i.invoice_no, i.uuid AS invoice_uuid, i.issue_date, i.due_date, i.period_month, i.unit_id, i.lease_id,
                bt.name AS bill_type, bt.code AS bill_code, bt.color_hex, bt.allocation_priority, u.unit_no, pr.name AS property_name
                FROM bln_invoice_lines l JOIN bln_invoices i ON i.id = l.invoice_id JOIN cfg_bill_types bt ON bt.id = l.bill_type_id
                LEFT JOIN prpt_units u ON u.id = i.unit_id JOIN prpt_properties pr ON pr.id = i.property_id
                WHERE " . implode(' AND ', $w) . " ORDER BY i.due_date, bt.allocation_priority, l.id", $prm);
}

/** Auto-allocate oldest-first by bill priority; leftover becomes a credit on the payer's account. */
function payment_auto_allocate(int $paymentId): array {
    $pay = one("SELECT * FROM fin_payments WHERE id = ?", [$paymentId]);
    if (!$pay['party_id']) return $pay;
    $left = (float)$pay['unallocated_amount']; $plan = [];
    foreach (open_lines_for((int)$pay['party_id'], $pay['lease_id'] ? (int)$pay['lease_id'] : null) as $ln) {
        if ($left <= 0) break; $a = min($left, (float)$ln['balance']); $plan[] = ['line_id' => (int)$ln['id'], 'amount' => round2($a)]; $left = round2($left - $a);
    }
    $credit = $left > 0 ? ['amount' => $left, 'party_id' => (int)$pay['party_id'], 'lease_id' => $pay['lease_id'], 'unit_id' => $pay['unit_id'], 'notes' => 'Auto: overpayment'] : null;
    if (!$plan && !$credit) return $pay;
    return payment_allocate($paymentId, $plan, $credit, 'auto');
}

/** Consume open credits into a (new) invoice — runs automatically when invoices are raised. */
function apply_open_credits(int $invoiceId): float {
    $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [$invoiceId]);
    $credits = all("SELECT * FROM fin_credits WHERE party_id = ? AND status = 'open' AND remaining > 0
                    AND (lease_id IS NULL OR lease_id = ?) AND (unit_id IS NULL OR unit_id = ?) ORDER BY bill_type_id IS NULL, created_at", [$inv['party_id'], $inv['lease_id'], $inv['unit_id']]);
    if (!$credits) return 0;
    $applied = 0.0;
    foreach ($credits as $c) {
        $rem = (float)$c['remaining'];
        $lines = all("SELECT * FROM bln_invoice_lines WHERE invoice_id = ? AND (amount + tax_amount - amount_paid) > 0.004" . ($c['bill_type_id'] ? ' AND bill_type_id = ' . (int)$c['bill_type_id'] : '') . " ORDER BY sort_order", [$invoiceId]);
        foreach ($lines as $ln) { if ($rem <= 0) break; $a = _apply_to_line($ln, $rem, null, (int)$c['id'], 'credit'); $rem = round2($rem - $a); $applied += $a; }
        update('fin_credits', ['remaining' => $rem, 'status' => $rem <= 0.004 ? 'consumed' : 'open'], 'id = :id', ['id' => $c['id']]);
    }
    if ($applied > 0) { $inv = invoice_recalc($invoiceId); invoice_event($invoiceId, 'credit_applied', 'Prepayment / overpayment credit consumed', $applied, (float)$inv['balance']); }
    return $applied;
}

function payment_reverse(int $paymentId, string $reason): void {
    tx(function () use ($paymentId, $reason) {
        $pay = one("SELECT * FROM fin_payments WHERE id = ? FOR UPDATE", [$paymentId]);
        if ($pay['status'] === 'reversed') throw new RuntimeException('Already reversed.');
        $used = (float)val("SELECT COALESCE(SUM(amount - remaining),0) FROM fin_credits WHERE payment_id = ? AND status <> 'cancelled'", [$paymentId]);
        if ($used > 0) throw new RuntimeException('Part of this payment\'s overpayment credit (' . money($used) . ') was already consumed by later invoices. Reverse those first.');
        foreach (all("SELECT * FROM fin_allocations WHERE payment_id = ? AND reversed_at IS NULL", [$paymentId]) as $a) {
            q("UPDATE bln_invoice_lines SET amount_paid = GREATEST(amount_paid - ?, 0) WHERE id = ?", [$a['amount'], $a['invoice_line_id']]);
            q("UPDATE fin_allocations SET reversed_at = NOW() WHERE id = ?", [$a['id']]);
            $inv = invoice_recalc((int)$a['invoice_id']);
            invoice_event((int)$a['invoice_id'], 'payment_reversed', "Payment {$pay['receipt_no']} reversed: $reason", -(float)$a['amount'], (float)$inv['balance']);
        }
        q("UPDATE fin_credits SET status = 'cancelled', remaining = 0 WHERE payment_id = ?", [$paymentId]);
        update('fin_payments', ['status' => 'reversed', 'reversed_reason' => $reason, 'allocated_amount' => 0, 'credit_amount' => 0, 'unallocated_amount' => 0], 'id = :id', ['id' => $paymentId]);
        audit('finance', 'reverse', 'payment', $paymentId, "Reversed {$pay['receipt_no']}: $reason");
    });
}
/** Undo allocations only (payment returns to unallocated so it can be re-consumed). */
function payment_unallocate(int $paymentId): void {
    tx(function () use ($paymentId) {
        $pay = one("SELECT * FROM fin_payments WHERE id = ? FOR UPDATE", [$paymentId]);
        $used = (float)val("SELECT COALESCE(SUM(amount - remaining),0) FROM fin_credits WHERE payment_id = ? AND status <> 'cancelled'", [$paymentId]);
        if ($used > 0) throw new RuntimeException('The overpayment from this payment has already been consumed.');
        foreach (all("SELECT * FROM fin_allocations WHERE payment_id = ? AND reversed_at IS NULL", [$paymentId]) as $a) {
            q("UPDATE bln_invoice_lines SET amount_paid = GREATEST(amount_paid - ?, 0) WHERE id = ?", [$a['amount'], $a['invoice_line_id']]);
            q("UPDATE fin_allocations SET reversed_at = NOW() WHERE id = ?", [$a['id']]);
            $inv = invoice_recalc((int)$a['invoice_id']);
            invoice_event((int)$a['invoice_id'], 'payment_reversed', "Allocation of {$pay['receipt_no']} undone for re-allocation", -(float)$a['amount'], (float)$inv['balance']);
        }
        q("UPDATE fin_credits SET status = 'cancelled', remaining = 0 WHERE payment_id = ?", [$paymentId]);
        payment_refresh($paymentId);
        audit('finance', 'unallocate', 'payment', $paymentId, "Cleared allocations of {$pay['receipt_no']}");
    });
}

/* ---------------- gateway feed → payments ---------------- */
/** Resolve who paid from the account reference a payer typed (unit no, lease no, party no, phone) */
function resolve_bill_ref(?string $ref, ?string $phone, ?int $accountId = null): array {
    $ref = strtoupper(trim((string)$ref));
    $props = $accountId ? array_map('intval', col("SELECT property_id FROM fin_property_accounts WHERE account_id = ?", [$accountId])) : [];
    if ($ref !== '') {
        if ($l = one("SELECT l.id, l.unit_id, l.property_id FROM bln_leases l WHERE UPPER(l.lease_no) = ?", [$ref])) return ['lease_id' => (int)$l['id'], 'unit_id' => (int)$l['unit_id'], 'property_id' => (int)$l['property_id'], 'party_id' => lease_primary_payer((int)$l['id'])];
        $units = all("SELECT u.id, u.property_id, pr.code FROM prpt_units u JOIN prpt_properties pr ON pr.id = u.property_id LEFT JOIN prpt_blocks b ON b.id = u.block_id
                      WHERE u.deleted_at IS NULL AND (UPPER(u.unit_no) = :r OR UPPER(CONCAT(pr.code, u.unit_no)) = :r OR UPPER(CONCAT(pr.code, '-', u.unit_no)) = :r OR UPPER(CONCAT(b.code, u.unit_no)) = :r OR UPPER(CONCAT(b.code, '-', u.unit_no)) = :r)", ['r' => $ref]);
        if ($props) $units = array_values(array_filter($units, fn($u) => in_array((int)$u['property_id'], $props, true))) ?: $units;
        if (count($units) === 1 && ($l = unit_active_lease((int)$units[0]['id']))) return ['lease_id' => (int)$l['id'], 'unit_id' => (int)$units[0]['id'], 'property_id' => (int)$units[0]['property_id'], 'party_id' => lease_primary_payer((int)$l['id'])];
        if ($p = one("SELECT id FROM prpt_parties WHERE UPPER(party_no) = ?", [$ref])) return ['party_id' => (int)$p['id']];
    }
    if ($phone && ($p = one("SELECT id FROM prpt_parties WHERE phone = ? AND deleted_at IS NULL", [normalize_phone($phone)]))) return ['party_id' => (int)$p['id']];
    return [];
}
function lease_primary_payer(int $leaseId): ?int {
    $v = val("SELECT party_id FROM bln_lease_parties WHERE lease_id = ? AND role IN ('tenant','payer','co_tenant') ORDER BY is_primary DESC, id LIMIT 1", [$leaseId])
      ?? val("SELECT party_id FROM bln_lease_parties WHERE lease_id = ? ORDER BY is_primary DESC, id LIMIT 1", [$leaseId]);
    return $v ? (int)$v : null;
}

/** Store a raw gateway transaction, match it and (optionally) auto-allocate. Idempotent per provider txn id. */
function ingest_gateway_txn(string $provider, array $t): array {
    $existing = one("SELECT * FROM fin_gateway_transactions WHERE provider = ? AND transaction_id = ?", [$provider, $t['transaction_id']]);
    if ($existing) return ['duplicate' => true, 'txn' => $existing];
    $id = insert('fin_gateway_transactions', ['provider' => $provider, 'account_id' => nullable($t['account_id'] ?? null), 'transaction_id' => $t['transaction_id'],
        'transaction_type' => nullable($t['transaction_type'] ?? null), 'amount' => round2((float)$t['amount']), 'payer_name' => nullable($t['payer_name'] ?? null),
        'payer_phone' => nullable($t['payer_phone'] ?? null), 'bill_ref' => nullable($t['bill_ref'] ?? null), 'transacted_at' => $t['transacted_at'] ?? date('Y-m-d H:i:s'),
        'raw_payload' => json_encode($t['raw'] ?? $t), 'is_simulated' => (int)!empty($t['simulated'])]);
    $txn = one("SELECT * FROM fin_gateway_transactions WHERE id = ?", [$id]);
    $who = resolve_bill_ref($txn['bill_ref'], $txn['payer_phone'], $txn['account_id'] ? (int)$txn['account_id'] : null);
    $method = ['mpesa' => 'mpesa', 'coop' => 'bank_transfer', 'stripe' => 'card'][$provider];
    $accountId = $txn['account_id'] ?: (int)val("SELECT id FROM fin_accounts WHERE integration_code LIKE ? AND is_master = 1 LIMIT 1", [$provider . '%']);
    $pay = payment_record(['account_id' => $accountId, 'method' => $method, 'source' => 'gateway', 'reference' => $txn['transaction_id'], 'amount' => $txn['amount'],
        'payer_name' => $txn['payer_name'], 'payer_phone' => $txn['payer_phone'], 'paid_at' => $txn['transacted_at'], 'gateway_txn_id' => $id,
        'party_id' => $who['party_id'] ?? null, 'unit_id' => $who['unit_id'] ?? null, 'lease_id' => $who['lease_id'] ?? null, 'property_id' => $who['property_id'] ?? null,
        'notes' => 'Bill ref: ' . ($txn['bill_ref'] ?: '—')]);
    update('fin_gateway_transactions', ['payment_id' => $pay['id'], 'match_status' => !empty($who['party_id']) ? 'matched' : 'unmatched'], 'id = :id', ['id' => $id]);
    if (!empty($who['party_id']) && setting('auto_allocate_gateway', true)) $pay = payment_auto_allocate((int)$pay['id']);
    if (empty($who['party_id'])) staff_notify_perm('finance.allocate', 'payment_unmatched', 'Unmatched ' . strtoupper($provider) . ' payment ' . money($txn['amount']), ($txn['payer_name'] ?: $txn['payer_phone']) . ' · ref ' . ($txn['bill_ref'] ?: '—'), 'fa-circle-question', page('finance/consume', ['payment' => $pay['uuid']]));
    return ['duplicate' => false, 'txn' => $txn, 'payment' => $pay, 'matched' => !empty($who['party_id'])];
}
