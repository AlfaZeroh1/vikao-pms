<?php
/** Sales module AJAX actions */
function _split_recalc(int $dealId): void {
    $d = one("SELECT commission_amount FROM sls_deals WHERE id = ?", [$dealId]);
    q("UPDATE sls_commission_splits SET amount = ROUND(? * share_pct / 100, 2) WHERE deal_id = ?", [(float)$d['commission_amount'], $dealId]);
}
return [
    'save-lead' => ['sales.leads.manage', function () {
        $d = validate($_POST, ['full_name' => 'required|min:2', 'interest' => 'required', 'source' => 'required', 'email' => 'email']);
        if (!input('phone') && !input('email')) throw new ValidationError(['phone' => 'Phone or email is required.']);
        $row = ['full_name' => $d['full_name'], 'phone' => normalize_phone(input('phone')) ?? nullable(input('phone')), 'email' => nullable(strtolower((string)input('email'))), 'source' => $d['source'], 'interest' => $d['interest'],
            'property_id' => input_int('property_id'), 'unit_id' => input_int('unit_id'), 'budget_min' => input_num('budget_min'), 'budget_max' => input_num('budget_max'), 'financing' => input('financing', 'undecided'),
            'score' => input_int('score'), 'assigned_to' => input_int('assigned_to') ?? staff_id(), 'next_follow_up_at' => input('next_follow_up_at') ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)input('next_follow_up_at')))) : null, 'notes' => nullable(input('notes'))];
        if ($row['unit_id'] && !$row['property_id']) $row['property_id'] = (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$row['unit_id']]);
        if ($id = input_int('id')) { update('sls_leads', $row, 'id = :id', ['id' => $id]); audit('sales', 'update', 'lead', $id, 'Updated lead ' . $row['full_name']); }
        else {
            $id = insert('sls_leads', $row + ['lead_no' => next_no('lead'), 'stage_id' => input_int('stage_id') ?? (int)val("SELECT id FROM sls_stages ORDER BY sort_order LIMIT 1"), 'created_by' => staff_id()]);
            lead_log($id, 'note', 'Lead created (' . str_replace('_', ' ', $row['source']) . ')');
            if ($row['assigned_to'] && $row['assigned_to'] !== staff_id()) staff_notify((int)$row['assigned_to'], 'lead', 'New lead assigned: ' . $row['full_name'], ucfirst($row['interest']) . ' · ' . ($row['phone'] ?: $row['email']), 'fa-user-plus', page('sales/lead', ['id' => $id]));
            audit('sales', 'create', 'lead', $id, 'New lead ' . $row['full_name']);
        }
        json_ok(['id' => $id, 'redirect' => input('id') ? null : page('sales/lead', ['id' => $id])], 'Lead saved.');
    }],
    'lead-stage' => ['sales.leads.manage', function () {
        $l = one("SELECT * FROM sls_leads WHERE id = ?", [input_int('id')]); $s = one("SELECT * FROM sls_stages WHERE id = ?", [input_int('stage_id')]);
        if (!$l || !$s) json_fail('Not found', 404); if ((int)$l['stage_id'] === (int)$s['id']) json_ok();
        $from = val("SELECT name FROM sls_stages WHERE id = ?", [$l['stage_id']]);
        update('sls_leads', ['stage_id' => $s['id'], 'lost_reason' => $s['is_lost'] ? (input('reason') ?: $l['lost_reason']) : null], 'id = :id', ['id' => $l['id']]);
        lead_log((int)$l['id'], 'stage_change', "Moved from $from to {$s['name']}" . ($s['is_lost'] && input('reason') ? ': ' . input('reason') : ''));
        json_ok([], 'Moved to ' . $s['name'] . '.');
    }],
    'log-activity' => ['sales.leads.manage', function () {
        $d = validate($_POST, ['lead_id' => 'required', 'activity_type' => 'required', 'summary' => 'required']);
        lead_log((int)$d['lead_id'], $d['activity_type'], $d['summary'], ['details' => input('details'), 'outcome' => input('outcome')]);
        if ($fu = input('follow_up_at')) {
            $at = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', (string)$fu)));
            insert('sls_activities', ['lead_id' => (int)$d['lead_id'], 'activity_type' => input('follow_up_type', 'call'), 'summary' => input('follow_up_note') ?: 'Follow up', 'due_at' => $at, 'user_id' => staff_id()]);
            q("UPDATE sls_leads SET next_follow_up_at = ? WHERE id = ?", [$at, $d['lead_id']]);
        }
        q("UPDATE sls_leads SET updated_at = NOW() WHERE id = ?", [$d['lead_id']]);
        json_ok([], 'Logged.');
    }],
    'done-activity' => ['sales.leads.manage', function () {
        $a = one("SELECT * FROM sls_activities WHERE id = ?", [input_int('id')]);
        q("UPDATE sls_activities SET done_at = NOW(), outcome = ? WHERE id = ?", [input('outcome') ?: null, $a['id']]);
        $next = val("SELECT MIN(due_at) FROM sls_activities WHERE lead_id = ? AND done_at IS NULL", [$a['lead_id']]);
        q("UPDATE sls_leads SET next_follow_up_at = ? WHERE id = ?", [$next, $a['lead_id']]);
        json_ok([], 'Follow-up done.');
    }],
    'convert-party' => ['sales.leads.manage', function () {
        $l = one("SELECT * FROM sls_leads WHERE id = ?", [input_int('id')]); if ($l['party_id']) json_ok(['redirect' => page('property/party', ['id' => $l['party_id']])]);
        [$fn, $ln] = array_pad(explode(' ', $l['full_name'], 2), 2, '');
        $pid = party_create(['first_name' => $fn, 'last_name' => $ln, 'email' => $l['email'], 'phone' => $l['phone'], 'is_tenant' => $l['interest'] === 'rent' ? 1 : 0, 'is_buyer' => $l['interest'] === 'buy' ? 1 : 0, 'notes' => 'From lead ' . $l['lead_no']]);
        q("UPDATE sls_leads SET party_id = ? WHERE id = ?", [$pid, $l['id']]); lead_log((int)$l['id'], 'note', 'Converted to a customer record');
        json_ok(['redirect' => $l['interest'] === 'rent' && $l['unit_id'] ? page('billing/lease-new', ['unit_id' => $l['unit_id']]) : page('property/party', ['id' => $pid])], 'Customer created.');
    }],
    /* ---------- deals ---------- */
    'create-deal' => ['sales.deals.manage', function () {
        $d = validate($_POST, ['lead_id' => 'required', 'unit_id' => 'required', 'agreed_price' => 'required|numeric']);
        $deal = deal_create((int)$d['lead_id'], $_POST);
        $st = val("SELECT id FROM sls_stages WHERE name LIKE '%egotiat%' OR name LIKE '%ffer%' ORDER BY sort_order LIMIT 1"); if ($st) q("UPDATE sls_leads SET stage_id = ? WHERE id = ? AND stage_id NOT IN (SELECT id FROM sls_stages WHERE is_won = 1)", [$st, $d['lead_id']]);
        json_ok(['redirect' => page('sales/deals', ['id' => $deal['id']])], 'Deal ' . $deal['deal_no'] . ' created — unit reserved.');
    }],
    'update-deal' => ['sales.deals.manage', function () {
        $deal = one("SELECT * FROM sls_deals WHERE id = ?", [input_int('id')]); if (!$deal) json_fail('Not found', 404);
        $row = ['agreed_price' => input_num('agreed_price', (float)$deal['agreed_price']), 'deposit_amount' => input_num('deposit_amount'), 'payment_plan' => input('payment_plan', $deal['payment_plan']), 'installments' => input_int('installments'),
            'commission_type' => input('commission_type', $deal['commission_type']), 'commission_value' => input_num('commission_value', (float)$deal['commission_value']), 'expected_close_date' => input_date('expected_close_date'), 'status' => input('status', $deal['status'])];
        $row['commission_amount'] = deal_commission($row);
        if ($row['status'] === 'completed' && $deal['status'] !== 'completed') { update('sls_deals', array_diff_key($row, ['status' => 1]), 'id = :id', ['id' => $deal['id']]); _split_recalc((int)$deal['id']); deal_complete((int)$deal['id']); json_ok([], 'Deal completed — ownership transferred to the buyer.'); }
        if ($row['status'] === 'cancelled' && $deal['status'] !== 'cancelled') set_unit_status((int)$deal['unit_id'], 'vacant', 'Deal ' . $deal['deal_no'] . ' cancelled');
        update('sls_deals', $row, 'id = :id', ['id' => $deal['id']]); _split_recalc((int)$deal['id']);
        lead_log((int)$deal['lead_id'], 'note', 'Deal ' . $deal['deal_no'] . ' updated: ' . str_replace('_', ' ', $row['status']));
        audit('sales', 'update', 'deal', $deal['id'], 'Updated deal ' . $deal['deal_no'], $deal, $row);
        json_ok([], 'Deal saved.');
    }],
    'invoice-deal' => ['billing.invoices.create', function () {
        $deal = one("SELECT d.*, l.party_id AS lp FROM sls_deals d JOIN sls_leads l ON l.id = d.lead_id WHERE d.id = ?", [input_int('id')]);
        $buyer = $deal['buyer_party_id'] ?: $deal['lp']; if (!$buyer) json_fail('Convert the lead to a customer first.');
        $amt = (float)input_num('amount', (float)($deal['deposit_amount'] ?: $deal['agreed_price'])); $bt = bill_type_by_code('SALE_INST') ?: bill_type_by_code('OTHER');
        $u = one("SELECT * FROM prpt_units WHERE id = ?", [$deal['unit_id']]);
        $inv = invoice_create(['party_id' => (int)$buyer, 'property_id' => (int)$u['property_id'], 'unit_id' => (int)$u['id'], 'deal_id' => (int)$deal['id'], 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+14 days')), 'source' => 'sale', 'notes' => 'Deal ' . $deal['deal_no']],
            [['bill_type_id' => (int)$bt['id'], 'description' => (input('label') ?: 'Purchase instalment') . ' — unit ' . $u['unit_no'] . ' (' . $deal['deal_no'] . ')', 'quantity' => 1, 'unit_price' => $amt]]);
        if ($deal['status'] === 'agreement_signed') q("UPDATE sls_deals SET status = 'paying' WHERE id = ?", [$deal['id']]);
        json_ok(['redirect' => page('billing/invoice', ['id' => $inv['id']])], 'Invoice ' . $inv['invoice_no'] . ' raised.');
    }],
    'save-split' => ['sales.deals.manage', function () {
        $dealId = (int)input_int('deal_id'); $pct = (float)input_num('share_pct', 0);
        if ($pct <= 0 || $pct > 100) throw new ValidationError(['share_pct' => 'Share must be between 0 and 100.']);
        if (!input_int('user_id') && !input_int('party_id')) throw new ValidationError(['user_id' => 'Choose a staff member or an external agent.']);
        $other = (float)val("SELECT COALESCE(SUM(share_pct),0) FROM sls_commission_splits WHERE deal_id = ? AND id <> ?", [$dealId, input_int('id') ?? 0]);
        if ($other + $pct > 100.001) throw new ValidationError(['share_pct' => 'Shares would exceed 100% (' . num($other, 1) . '% already allocated).']);
        $row = ['deal_id' => $dealId, 'user_id' => input_int('user_id'), 'party_id' => input_int('party_id'), 'share_pct' => $pct];
        if ($id = input_int('id')) update('sls_commission_splits', $row, 'id = :id', ['id' => $id]); else insert('sls_commission_splits', $row);
        _split_recalc($dealId); json_ok([], 'Commission split saved.');
    }],
    'split-status' => ['sales.commissions.approve', function () {
        $s = (string)input('status'); if (!in_array($s, ['approved', 'paid', 'pending'], true)) json_fail('Bad status');
        q("UPDATE sls_commission_splits SET status = ?, paid_at = IF(? = 'paid', NOW(), paid_at) WHERE id = ?", [$s, $s, input_int('id')]);
        audit('sales', 'commission_' . $s, 'commission', input_int('id'), 'Commission ' . $s); json_ok([], 'Commission ' . $s . '.');
    }],
    'delete-split' => ['sales.deals.manage', function () { q("DELETE FROM sls_commission_splits WHERE id = ? AND status = 'pending'", [input_int('id')]); json_ok([], 'Removed.'); }],
];
