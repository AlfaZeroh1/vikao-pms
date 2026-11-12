<?php
/** Sales engine: leads → deals → commission splits; sale agreement + buyer ownership on completion. */

function lead_log(int $leadId, string $type, string $summary, array $extra = []): void {
    insert('sls_activities', ['lead_id' => $leadId, 'activity_type' => $type, 'summary' => mb_substr($summary, 0, 255), 'details' => nullable($extra['details'] ?? null),
        'outcome' => nullable($extra['outcome'] ?? null), 'due_at' => nullable($extra['due_at'] ?? null), 'done_at' => $extra['done_at'] ?? date('Y-m-d H:i:s'), 'user_id' => staff_id()]);
}
function deal_commission(array $deal): float {
    return round2($deal['commission_type'] === 'percentage' ? (float)$deal['agreed_price'] * (float)$deal['commission_value'] / 100 : (float)$deal['commission_value']);
}
/** Default commission terms from the owner's management agreement */
function default_sales_commission(int $unitId): array {
    $u = one("SELECT property_id FROM prpt_units WHERE id = ?", [$unitId]);
    $owner = unit_primary_owner_id($unitId);
    $ag = $owner ? mgmt_agreement_for($owner, (int)$u['property_id']) : null;
    return ['type' => $ag['sales_commission_type'] ?? 'percentage', 'value' => (float)($ag['sales_commission_value'] ?? setting('default_sales_commission_pct', 3)), 'owner_party_id' => $owner];
}
function deal_create(int $leadId, array $d): array {
    $lead = one("SELECT * FROM sls_leads WHERE id = ?", [$leadId]);
    $unit = one("SELECT * FROM prpt_units WHERE id = ?", [(int)$d['unit_id']]);
    if (!$unit) throw new ValidationError(['unit_id' => 'Choose the unit being sold.']);
    $def = default_sales_commission((int)$unit['id']);
    $deal = ['deal_no' => next_no('deal'), 'lead_id' => $leadId, 'unit_id' => (int)$unit['id'], 'agreed_price' => (float)$d['agreed_price'], 'deposit_amount' => nullable($d['deposit_amount'] ?? null),
        'payment_plan' => $d['payment_plan'] ?? 'cash', 'installments' => nullable($d['installments'] ?? null), 'status' => $d['status'] ?? 'offer',
        'commission_type' => $d['commission_type'] ?? $def['type'], 'commission_value' => (float)($d['commission_value'] ?? $def['value']),
        'owner_party_id' => $def['owner_party_id'], 'expected_close_date' => nullable($d['expected_close_date'] ?? null), 'created_by' => staff_id()];
    $deal['commission_amount'] = deal_commission($deal);
    $id = insert('sls_deals', $deal);
    if ($lead['assigned_to']) insert('sls_commission_splits', ['deal_id' => $id, 'user_id' => $lead['assigned_to'], 'share_pct' => 100, 'amount' => $deal['commission_amount']]);
    set_unit_status((int)$unit['id'], 'reserved', 'Deal ' . $deal['deal_no']);
    lead_log($leadId, 'offer', 'Deal ' . $deal['deal_no'] . ' created at ' . money($deal['agreed_price']));
    audit('sales', 'create', 'deal', $id, 'Deal ' . $deal['deal_no']);
    return one("SELECT * FROM sls_deals WHERE id = ?", [$id]);
}
/** Close a deal: buyer becomes a party + owner of the unit; unit marked sold; stage won. */
function deal_complete(int $dealId): void {
    tx(function () use ($dealId) {
        $d = one("SELECT d.*, l.full_name, l.email, l.phone, l.party_id AS lead_party FROM sls_deals d JOIN sls_leads l ON l.id = d.lead_id WHERE d.id = ?", [$dealId]);
        $buyer = $d['buyer_party_id'] ?: $d['lead_party'];
        if (!$buyer) { [$fn, $ln] = array_pad(explode(' ', $d['full_name'], 2), 2, ''); $buyer = party_create(['first_name' => $fn, 'last_name' => $ln, 'email' => $d['email'], 'phone' => $d['phone'], 'is_owner' => 1, 'is_buyer' => 1]); }
        q("UPDATE prpt_parties SET is_owner = 1, is_buyer = 1 WHERE id = ?", [$buyer]);
        $unit = one("SELECT * FROM prpt_units WHERE id = ?", [$d['unit_id']]);
        q("UPDATE prpt_ownerships SET end_date = CURDATE() WHERE scope = 'unit' AND unit_id = ? AND end_date IS NULL", [$d['unit_id']]);
        insert('prpt_ownerships', ['party_id' => $buyer, 'scope' => 'unit', 'property_id' => $unit['property_id'], 'unit_id' => $unit['id'], 'share_pct' => 100, 'acquisition' => 'purchase', 'start_date' => date('Y-m-d'), 'notes' => 'Deal ' . $d['deal_no']]);
        update('sls_deals', ['status' => 'completed', 'closed_at' => date('Y-m-d H:i:s'), 'buyer_party_id' => $buyer], 'id = :id', ['id' => $dealId]);
        $won = val("SELECT id FROM sls_stages WHERE is_won = 1 LIMIT 1");
        update('sls_leads', ['stage_id' => $won, 'party_id' => $buyer], 'id = :id', ['id' => $d['lead_id']]);
        set_unit_status((int)$d['unit_id'], 'sold', 'Deal ' . $d['deal_no'] . ' completed');
        q("UPDATE sls_commission_splits SET status = 'approved' WHERE deal_id = ? AND status = 'pending'", [$dealId]);
        portal_ensure_account((int)$buyer);
        lead_log((int)$d['lead_id'], 'stage_change', 'Deal completed — unit transferred to buyer');
        audit('sales', 'complete', 'deal', $dealId, "Deal {$d['deal_no']} completed");
    });
}
