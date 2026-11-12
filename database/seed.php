<?php
/** Reference data: roles & permissions, settings, bill types, sequences, templates, stages, categories, integrations. */

/* ---------------- permissions ---------------- */
$PERMS = [
    'dashboard' => ['dashboard.view' => 'View dashboard'],
    'admin' => ['admin.users.view' => 'View staff users', 'admin.users.manage' => 'Manage staff users', 'admin.roles.manage' => 'Manage roles & permissions', 'admin.audit.view' => 'View audit trail', 'admin.online.view' => 'See who is online'],
    'config' => ['config.settings.manage' => 'Manage system settings', 'config.billtypes.manage' => 'Manage bill types, taxes & penalties', 'config.lookups.manage' => 'Manage lists', 'config.integrations.manage' => 'Manage integrations (API module)'],
    'property' => ['property.properties.view' => 'View properties & units', 'property.properties.manage' => 'Create / edit properties, blocks, units', 'property.parties.view' => 'View owners & tenants',
        'property.parties.manage' => 'Create / edit owners & tenants', 'property.ownership.manage' => 'Manage ownership', 'property.agreements.manage' => 'Manage management agreements',
        'property.meters.manage' => 'Manage meters', 'property.readings.capture' => 'Capture meter readings', 'property.readings.approve' => 'Approve readings & assignments',
        'property.templates.manage' => 'Manage document templates', 'property.documents.manage' => 'Manage documents', 'property.portal.invite' => 'Send portal activation links'],
    'billing' => ['billing.leases.view' => 'View billing accounts', 'billing.leases.manage' => 'Open / edit / end accounts', 'billing.invoices.view' => 'View invoices', 'billing.invoices.create' => 'Create manual invoices',
        'billing.invoices.edit' => 'Edit invoices', 'billing.invoices.send' => 'Send invoices', 'billing.invoices.void' => 'Void / credit invoices', 'billing.runs.execute' => 'Run billing', 'billing.arrears.view' => 'View arrears', 'billing.deposits.manage' => 'Manage deposits'],
    'finance' => ['finance.payments.view' => 'View payments', 'finance.payments.create' => 'Record payments', 'finance.payments.reverse' => 'Reverse payments', 'finance.allocate' => 'Consume / allocate payments',
        'finance.gateways.view' => 'View M-Pesa / Co-op / Stripe feeds', 'finance.accounts.manage' => 'Manage collection accounts', 'finance.expenses.manage' => 'Manage expenses',
        'finance.remittances.manage' => 'Prepare owner remittances', 'finance.remittances.approve' => 'Approve & pay remittances', 'finance.statements.view' => 'Generate statements', 'finance.qb.export' => 'QuickBooks exports'],
    'comms' => ['comms.templates.manage' => 'Manage templates', 'comms.send' => 'Send messages & campaigns', 'comms.log.view' => 'View message log'],
    'sales' => ['sales.leads.manage' => 'Manage leads', 'sales.deals.manage' => 'Manage deals', 'sales.commissions.approve' => 'Approve commissions'],
    'crm' => ['crm.tickets.view' => 'View tickets', 'crm.tickets.manage' => 'Work tickets', 'crm.maintenance.manage' => 'Vendors, schedules & inspections'],
    'shortstay' => ['shortstay.view' => 'View short stays', 'shortstay.manage' => 'Manage listings, rates & bookings'],
    'ecommerce' => ['ecommerce.view' => 'View marketplace', 'ecommerce.moderate' => 'Approve / decline ads', 'ecommerce.accounts' => 'Manage marketplace accounts', 'ecommerce.official' => 'Post official listings', 'ecommerce.integrations' => 'Marketplace payment integrations'],
    'reports' => ['reports.view' => 'View reports', 'reports.ai' => 'Generate AI insights'],
];
$i = 0;
foreach ($PERMS as $mod => $list) foreach ($list as $code => $name) insert('sys_permissions', ['code' => $code, 'module' => $mod, 'name' => $name, 'sort_order' => $i++]);
$ROLES = [
    ['super_admin', 'Super Admin', 'Full access to everything', 1, 1, ['*']],
    ['property_manager', 'Property Manager', 'Runs properties, tenants, billing and CRM', 1, 1, ['dashboard', 'property', 'billing', 'finance.payments.view', 'finance.statements.view', 'finance.expenses.manage', 'comms', 'crm', 'reports.view', 'shortstay', 'admin.online.view']],
    ['accountant', 'Accountant', 'Payments, allocation, remittances, QuickBooks', 1, 1, ['dashboard', 'finance', 'billing.invoices.view', 'billing.arrears.view', 'billing.leases.view', 'billing.invoices.void', 'billing.deposits.manage', 'property.properties.view', 'property.parties.view', 'reports']],
    ['meter_reader', 'Meter Reader', 'Field meter readings only', 1, 0, ['dashboard.view', 'property.readings.capture']],
    ['crm_agent', 'Customer Care', 'Tickets, communication, maintenance', 1, 1, ['dashboard.view', 'crm', 'comms.send', 'comms.log.view', 'property.properties.view', 'property.parties.view', 'billing.invoices.view', 'billing.arrears.view']],
    ['sales_agent', 'Sales Agent', 'Leads and deals', 1, 1, ['dashboard.view', 'sales.leads.manage', 'sales.deals.manage', 'property.properties.view']],
    ['sst_manager', 'Short Stay Manager', 'Short-stay operations', 1, 1, ['dashboard.view', 'shortstay', 'finance.payments.view', 'finance.payments.create', 'finance.allocate', 'crm.tickets.view']],
    ['ecom_admin', 'Marketplace Admin', 'Manages vikao.io', 1, 1, ['dashboard.view', 'ecommerce', 'property.properties.view']],
    ['viewer', 'Read Only', 'View-only access', 1, 1, ['dashboard.view', 'property.properties.view', 'property.parties.view', 'billing.leases.view', 'billing.invoices.view', 'billing.arrears.view', 'finance.payments.view', 'crm.tickets.view', 'reports.view']],
];
$allPerms = all("SELECT id, code FROM sys_permissions");
foreach ($ROLES as [$code, $name, $desc, $sys, $scope, $grants]) {
    $rid = insert('sys_roles', ['code' => $code, 'name' => $name, 'description' => $desc, 'is_system' => $sys, 'scope_all_properties' => $scope]);
    if ($grants === ['*']) continue;
    foreach ($allPerms as $p) foreach ($grants as $g) if ($p['code'] === $g || str_starts_with($p['code'], $g . '.')) { q("INSERT IGNORE INTO sys_role_permissions (role_id, permission_id) VALUES (?, ?)", [$rid, $p['id']]); break; }
}

/* ---------------- settings ---------------- */
$S = [
    ['company_name', 'Vikao Properties Ltd', 'string', 'company', 'Company name'], ['company_email', 'accounts@vikao.io', 'string', 'company', 'Company email'],
    ['company_phone', '+254 700 100 200', 'string', 'company', 'Company phone'], ['company_address', 'Westlands, Nairobi, Kenya', 'string', 'company', 'Address'],
    ['company_kra_pin', 'P051234567X', 'string', 'company', 'KRA PIN'], ['paybill_number', '4123456', 'string', 'payments', 'Vikao M-Pesa paybill (shown on invoices)'],
    ['bank_details', 'Co-operative Bank · Westlands · A/C 01100123456700', 'string', 'payments', 'Bank details on invoices'],
    ['auto_allocate_gateway', '1', 'bool', 'payments', 'Auto-allocate matched M-Pesa / bank / card payments (oldest bill first)'],
    ['billing_prorate_first_month', '1', 'bool', 'billing', 'Prorate the first month of a new lease'],
    ['billing_auto_send', '1', 'bool', 'billing', 'Email invoices automatically after an automatic billing run'],
    ['billing_auto_run_day', '1', 'int', 'billing', 'Day of month the scheduler runs automatic invoicing'],
    ['invoice_terms', 'Payment is due by the date shown. Use your unit number as the account number when paying via M-Pesa paybill.', 'text', 'billing', 'Invoice footer / terms'],
    ['vat_rate', '16', 'decimal', 'tax', 'VAT rate (%) on management fees'], ['remittance_withholding_pct', '0', 'decimal', 'tax', 'Withhold from owner remittances (%) e.g. MRI 7.5'],
    ['default_sales_commission_pct', '3', 'decimal', 'sales', 'Default sales commission (%)'],
    ['readings_require_approval', '0', 'bool', 'meters', 'Field readings need supervisor approval'], ['readings_hold_anomalies', '1', 'bool', 'meters', 'Hold anomalous readings for review'],
    ['reading_anomaly_factor', '2.5', 'decimal', 'meters', 'Flag readings above N × average consumption'], ['reading_photo_required', '1', 'bool', 'meters', 'Photo required for field readings'],
    ['crm_auto_ack', '1', 'bool', 'crm', 'Auto-acknowledge new portal tickets by email'], ['portal_allow_card', '1', 'bool', 'portal', 'Allow card (Stripe) payments in the portal'],
    ['portal_allow_stk', '1', 'bool', 'portal', 'Allow M-Pesa STK push from the portal'], ['portal_welcome', 'Everything about your home, in one place.', 'string', 'portal', 'Portal welcome line'],
];
foreach ($S as [$k, $v, $t, $g, $l]) insert('cfg_settings', ['setting_key' => $k, 'setting_value' => $v, 'value_type' => $t, 'group_name' => $g, 'label' => $l]);

insert('cfg_tax_rates', ['code' => 'VAT16', 'name' => 'VAT 16%', 'rate_pct' => 16]);
insert('cfg_tax_rates', ['code' => 'MRI', 'name' => 'Monthly Rental Income tax', 'rate_pct' => 7.5]);
insert('cfg_tax_rates', ['code' => 'LEVY2', 'name' => 'Tourism levy 2%', 'rate_pct' => 2]);

/* ---------------- bill types ---------------- */
$BT = [
    // code, name, category, method, freq, amount, rate, unit, bill_to, remit_to, refundable, priority, icon, color, qb_item
    ['RENT', 'Rent', 'rent', 'fixed', 'monthly', null, null, null, 'tenant', 'owner', 0, 30, 'fa-house-chimney', '#D97706', 'Rental Income'],
    ['SC', 'Service Charge', 'service_charge', 'fixed', 'monthly', null, null, null, 'owner', 'property_fund', 0, 20, 'fa-building-shield', '#0EA5E9', 'Service Charge'],
    ['WATER', 'Water', 'utility', 'metered', 'monthly', null, 150, 'm³', 'tenant', 'provider', 0, 10, 'fa-droplet', '#06B6D4', 'Water Recharge'],
    ['ELEC', 'Electricity', 'utility', 'metered', 'monthly', null, 28, 'kWh', 'tenant', 'provider', 0, 10, 'fa-bolt', '#EAB308', 'Electricity Recharge'],
    ['GAS', 'Gas', 'utility', 'metered', 'monthly', null, 320, 'kg', 'tenant', 'provider', 0, 10, 'fa-fire-flame-simple', '#F97316', 'Gas Recharge'],
    ['GARBAGE', 'Garbage Collection', 'utility', 'fixed', 'monthly', 500, null, null, 'tenant', 'provider', 0, 12, 'fa-dumpster', '#22C55E', 'Garbage'],
    ['SECURITY', 'Security Levy', 'utility', 'fixed', 'monthly', 1000, null, null, 'tenant', 'property_fund', 0, 15, 'fa-shield-halved', '#64748B', 'Security'],
    ['PARKING', 'Parking', 'other', 'fixed', 'monthly', 2000, null, null, 'tenant', 'owner', 0, 35, 'fa-square-parking', '#8B5CF6', 'Parking Income'],
    ['DEP_RENT', 'Rent Deposit', 'deposit', 'one_off', 'one_off', null, null, null, 'tenant', 'agency', 1, 5, 'fa-vault', '#475569', 'Tenant Deposits (Liability)'],
    ['DEP_WATER', 'Water Deposit', 'deposit', 'one_off', 'one_off', 2500, null, null, 'tenant', 'agency', 1, 6, 'fa-vault', '#475569', 'Utility Deposits (Liability)'],
    ['PENALTY', 'Late Payment Penalty', 'penalty', 'one_off', 'one_off', null, null, null, 'tenant', 'agency', 0, 60, 'fa-gavel', '#EF4444', 'Penalty Income'],
    ['SST_ACC', 'Short Stay Accommodation', 'short_stay', 'one_off', 'per_booking', null, null, null, 'tenant', 'owner', 0, 25, 'fa-bed', '#EC4899', 'Short Stay Income'],
    ['SST_CLEAN', 'Cleaning Fee', 'short_stay', 'one_off', 'per_booking', null, null, null, 'tenant', 'agency', 0, 26, 'fa-broom', '#14B8A6', 'Cleaning Income'],
    ['SALE_INST', 'Sale Instalment', 'sale', 'one_off', 'one_off', null, null, null, 'tenant', 'owner', 0, 40, 'fa-handshake', '#10B981', 'Property Sales'],
    ['OTHER', 'Other Charge', 'other', 'one_off', 'one_off', null, null, null, 'tenant', 'agency', 0, 70, 'fa-receipt', '#94A3B8', 'Other Income'],
];
foreach ($BT as $o => [$code, $name, $cat, $method, $freq, $amt, $rate, $unit, $billTo, $remit, $refund, $prio, $icon, $color, $qb])
    insert('cfg_bill_types', ['code' => $code, 'name' => $name, 'category' => $cat, 'billing_method' => $method, 'frequency' => $freq, 'default_amount' => $amt, 'default_unit_rate' => $rate,
        'unit_label' => $unit, 'default_bill_to' => $billTo, 'remit_to' => $remit, 'is_refundable' => $refund, 'allocation_priority' => $prio, 'icon' => $icon, 'color_hex' => $color,
        'qb_item' => $qb, 'qb_income_account' => $qb, 'is_system' => in_array($code, ['RENT', 'SC', 'DEP_RENT', 'PENALTY', 'SST_ACC', 'SST_CLEAN', 'SALE_INST'], true) ? 1 : 0, 'sort_order' => $o]);
// Nairobi-style tiered water tariff (global)
$w = (int)val("SELECT id FROM cfg_bill_types WHERE code = 'WATER'");
foreach ([[0, 6, 120], [6, 60, 150], [60, null, 180]] as [$a, $b, $r]) insert('cfg_tariff_tiers', ['bill_type_id' => $w, 'from_units' => $a, 'to_units' => $b, 'rate' => $r]);
insert('cfg_penalty_rules', ['name' => 'Rent 10% after 5 days', 'applies_to_bill_type_id' => (int)val("SELECT id FROM cfg_bill_types WHERE code = 'RENT'"), 'grace_days' => 5, 'penalty_type' => 'percentage', 'penalty_value' => 10, 'max_penalty' => 10000, 'is_active' => 0]);

foreach ([['invoice', 'INV', 5, 1], ['receipt', 'RCT', 5, 1], ['lease', 'ACC', 5, 0], ['ticket', 'TKT', 5, 1], ['booking', 'BKG', 5, 1], ['remittance', 'REM', 4, 1], ['lead', 'LD', 5, 0],
          ['deal', 'DL', 4, 1], ['credit_note', 'CN', 4, 1], ['run', 'RUN', 4, 1], ['expense', 'EXP', 5, 1], ['party', 'PTY', 5, 0]] as [$k, $p, $pad, $y])
    insert('cfg_sequences', ['seq_key' => $k, 'prefix' => $p, 'pad_length' => $pad, 'reset_yearly' => $y, 'current_year' => (int)date('Y')]);

foreach (['studio' => 'Studio', 'bedsitter' => 'Bedsitter', 'apartment' => 'Apartment', 'maisonette' => 'Maisonette', 'townhouse' => 'Townhouse', 'bungalow' => 'Bungalow', 'villa' => 'Villa', 'penthouse' => 'Penthouse',
          'office' => 'Office', 'shop' => 'Shop', 'warehouse' => 'Warehouse', 'kiosk' => 'Kiosk', 'plot' => 'Plot', 'parking' => 'Parking bay'] as $c => $l) insert('cfg_lookups', ['list_key' => 'unit_type', 'code' => $c, 'label' => $l]);

/* ---------------- finance ---------------- */
foreach ([['VK-MPESA', 'Vikao M-Pesa Paybill 4123456', 'mpesa_paybill', 'safaricom', '4123456', 'mpesa_vikao', 'M-Pesa Clearing'], ['VK-COOP', 'Vikao Co-op Bank 0110…7700', 'bank', 'coop', '01100123456700', 'coop_vikao', 'Co-op Bank Current'],
          ['VK-STRIPE', 'Vikao Stripe (cards)', 'stripe', 'stripe', null, 'stripe_vikao', 'Stripe Clearing'], ['VK-CASH', 'Cash Office', 'cash', null, null, null, 'Petty Cash']] as [$c, $n, $t, $pv, $num, $int, $qb])
    insert('fin_accounts', ['code' => $c, 'name' => $n, 'account_type' => $t, 'provider' => $pv, 'number' => $num, 'holder' => 'agency', 'is_master' => 1, 'integration_code' => $int, 'qb_account' => $qb]);
foreach ([['REPAIRS', 'Repairs & Maintenance', 'owner'], ['CLEANING', 'Cleaning & Sanitation', 'property_fund'], ['SECURITY', 'Security Services', 'property_fund'], ['UTILITIES', 'Utility Bills (bulk)', 'property_fund'],
          ['INSURANCE', 'Insurance', 'owner'], ['RATES', 'Land Rates & Rent', 'owner'], ['LEGAL', 'Legal & Professional', 'owner'], ['MARKETING', 'Marketing & Listings', 'agency'], ['OFFICE', 'Office & Admin', 'agency'],
          ['SST_SUPPLIES', 'Short Stay Supplies', 'owner'], ['COMMISSION', 'Commissions Paid', 'agency']] as [$c, $n, $ch])
    insert('fin_expense_categories', ['code' => $c, 'name' => $n, 'qb_expense_account' => $n, 'default_chargeable_to' => $ch]);

/* ---------------- integrations (silent API module) ---------------- */
foreach ([['mpesa_vikao', 'M-Pesa — Vikao Paybill', 'mpesa', 'pms', ['shortcode' => '4123456']], ['coop_vikao', 'Co-operative Bank — Vikao', 'coop', 'pms', ['account_number' => '01100123456700']],
          ['stripe_vikao', 'Stripe — Vikao', 'stripe', 'pms', ['currency' => 'kes']], ['mpesa_ecom', 'M-Pesa — Vikao Marketplace', 'mpesa', 'ecommerce', ['shortcode' => '4123499']],
          ['stripe_ecom', 'Stripe — Vikao Marketplace', 'stripe', 'ecommerce', ['currency' => 'kes']], ['smtp', 'SMTP email', 'smtp', 'shared', ['host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'tls', 'from_email' => 'no-reply@vikao.io', 'from_name' => 'Vikao']],
          ['sms_at', "Africa's Talking SMS", 'africastalking', 'shared', ['username' => 'sandbox', 'sender_id' => 'VIKAO']], ['anthropic', 'AI insights (Anthropic)', 'anthropic', 'shared', ['model' => 'claude-sonnet-4-5']]] as [$c, $n, $p, $s, $cfg])
    insert('api_integrations', ['code' => $c, 'name' => $n, 'provider' => $p, 'scope' => $s, 'settings' => json_encode($cfg), 'credentials' => json_encode(new stdClass()), 'simulate' => 1, 'is_active' => 1]);

/* ---------------- comms templates ---------------- */
$T = [
    ['portal_activation', 'Portal activation', 'portal', 'email', 'Activate your Vikao self-service account', '<p>Hi {{first_name}},</p><p>{{company_name}} has created a self-service account for you. See your units, invoices, payments, statements and raise requests — anytime, from your phone.</p><p><a href="{{activation_link}}" style="display:inline-block;background:#D97706;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:700">Activate my account</a></p><p style="color:#64748B;font-size:13px">This link expires in 7 days.</p>', null],
    ['invoice_issued', 'Invoice issued', 'billing', 'both', 'Invoice {{invoice_no}} — {{amount|money}} due {{due_date}}', '<p>Dear {{first_name}},</p><p>Your invoice <b>{{invoice_no}}</b> for <b>{{property_name}} · {{unit_no}}</b> ({{period}}) is ready.</p>{{lines_table}}<p style="font-size:18px"><b>Total: {{amount|money}}</b> · due <b>{{due_date}}</b></p><p>Pay via M-Pesa Paybill <b>{{paybill}}</b>, account <b>{{account_ref}}</b>, or online in your portal.</p><p><a href="{{portal_link}}">View invoice</a></p>', 'Vikao: Invoice {{invoice_no}} {{amount|money}} due {{due_date}}. Paybill {{paybill}} A/C {{account_ref}}.'],
    ['payment_receipt', 'Payment receipt', 'billing', 'both', 'Payment received — {{amount|money}}', '<p>Dear {{first_name}}, we received <b>{{amount|money}}</b> (ref {{reference}}). Receipt <b>{{receipt_no}}</b>. Your balance is now <b>{{balance|money}}</b>.</p>', 'Vikao: Received {{amount|money}} ref {{reference}}. Balance {{balance|money}}. Thank you.'],
    ['arrears_reminder', 'Arrears reminder', 'reminder', 'both', 'Friendly reminder: {{balance|money}} outstanding', '<p>Dear {{first_name}}, our records show an outstanding balance of <b>{{balance|money}}</b>. Kindly settle via Paybill <b>{{paybill}}</b> or through your portal. Ignore if already paid.</p>', 'Vikao: Your balance of {{balance|money}} is overdue. Paybill {{paybill}}. Ignore if paid.'],
    ['invoice_due_soon', 'Invoice due soon', 'reminder', 'sms', 'Invoice {{invoice_no}} due {{due_date}}', '<p>Invoice {{invoice_no}} ({{balance|money}}) is due on {{due_date}}.</p>', 'Vikao: Invoice {{invoice_no}} ({{balance|money}}) due {{due_date}}. Paybill {{paybill}} A/C {{unit_no}}.'],
    ['lease_expiry', 'Lease expiry notice', 'lease', 'both', 'Your lease {{lease_no}} ends {{end_date}}', '<p>Dear {{first_name}}, your lease for unit {{unit_no}} ends on <b>{{end_date}}</b>. Reply or raise a request in your portal to renew.</p>', 'Vikao: Lease {{lease_no}} for {{unit_no}} ends {{end_date}}. Contact us to renew.'],
    ['ticket_received', 'Ticket received', 'crm', 'email', 'We received your request {{ticket_no}}', '<p>Hi {{first_name}}, thanks for reaching out. Your request <b>{{ticket_no}} — {{subject}}</b> has been logged. Track progress in your portal.</p>', null],
    ['ticket_update', 'Ticket update', 'crm', 'both', 'Update on {{ticket_no}}: {{status}}', '<p>Hi {{first_name}}, your request <b>{{ticket_no}}</b> is now <b>{{status}}</b>.</p><blockquote>{{message}}</blockquote>', 'Vikao: Request {{ticket_no}} is now {{status}}.'],
    ['remittance_paid', 'Remittance paid', 'billing', 'email', 'Remittance {{remittance_no}} paid — {{amount|money}}', '<p>Dear {{first_name}}, we have remitted <b>{{amount|money}}</b> for {{property_name}} ({{period}}). The statement is in your portal.</p>', null],
    ['booking_confirmation', 'Booking confirmation', 'short_stay', 'both', 'Booking {{booking_no}} confirmed', '<p>Hi {{first_name}}, your stay at <b>{{listing}}</b> is confirmed: {{check_in}} → {{check_out}} ({{nights}} nights). Total {{amount|money}}. Check-in from {{check_in_time}}.</p>', 'Vikao: Booking {{booking_no}} confirmed {{check_in}}-{{check_out}}. Total {{amount|money}}.'],
    ['booking_checkin', 'Check-in instructions', 'short_stay', 'both', 'Check-in details for tomorrow', '<p>Hi {{first_name}}, welcome! Check-in from {{check_in_time}} on {{check_in}}.</p><p>{{access_instructions}}</p><p>Wi-Fi: {{wifi}}</p>', 'Vikao: Check-in {{check_in}} from {{check_in_time}}. Wi-Fi {{wifi}}.'],
    ['announcement', 'General announcement', 'announcement', 'both', '{{subject}}', '<p>Dear {{first_name}},</p><p>{{message}}</p>', '{{message}}'],
];
foreach ($T as [$c, $n, $cat, $ch, $sub, $em, $sms]) insert('com_templates', ['code' => $c, 'name' => $n, 'category' => $cat, 'channel' => $ch, 'subject' => $sub, 'body_email' => $em, 'body_sms' => $sms, 'is_system' => 1]);
$tid = fn($c) => (int)val("SELECT id FROM com_templates WHERE code = ?", [$c]);
insert('com_reminder_rules', ['name' => '3 days before due', 'trigger_type' => 'invoice_due', 'offset_days' => -3, 'template_id' => $tid('invoice_due_soon'), 'channel' => 'sms', 'min_amount' => 100]);
insert('com_reminder_rules', ['name' => '7 days overdue', 'trigger_type' => 'invoice_overdue', 'offset_days' => 7, 'template_id' => $tid('arrears_reminder'), 'channel' => 'both', 'min_amount' => 500]);
insert('com_reminder_rules', ['name' => 'Lease ends in 60 days', 'trigger_type' => 'lease_expiry', 'offset_days' => -60, 'template_id' => $tid('lease_expiry'), 'channel' => 'both']);
insert('com_reminder_rules', ['name' => 'Guest check-in eve', 'trigger_type' => 'booking_checkin', 'offset_days' => -1, 'template_id' => $tid('booking_checkin'), 'channel' => 'both']);

/* ---------------- sales / crm ---------------- */
foreach ([['New lead', 10, '#64748B'], ['Contacted', 20, '#0EA5E9'], ['Site visit', 40, '#8B5CF6'], ['Negotiation', 60, '#F59E0B'], ['Reserved', 80, '#D97706'], ['Won', 100, '#10B981', 1, 0], ['Lost', 0, '#EF4444', 0, 1]] as $o => $s)
    insert('sls_stages', ['name' => $s[0], 'probability' => $s[1], 'color_hex' => $s[2], 'is_won' => $s[3] ?? 0, 'is_lost' => $s[4] ?? 0, 'sort_order' => $o]);
foreach ([['Plumbing & water', 'fa-faucet-drip', 'maintenance', 24], ['Electrical', 'fa-bolt', 'maintenance', 24], ['Structural & doors', 'fa-door-open', 'maintenance', 72], ['Appliances', 'fa-blender', 'maintenance', 72],
          ['Security concern', 'fa-shield-halved', 'security', 4], ['Noise / neighbour complaint', 'fa-volume-high', 'complaint', 48], ['Billing query', 'fa-file-invoice-dollar', 'billing', 24],
          ['Cleaning & garbage', 'fa-broom', 'complaint', 24], ['Lift / common areas', 'fa-elevator', 'maintenance', 12], ['Move-out / notice', 'fa-truck-moving', 'request', 72],
          ['General inquiry', 'fa-circle-question', 'inquiry', 48], ['Suggestion / feedback', 'fa-lightbulb', 'feedback', 120]] as $o => [$n, $i, $k, $sla])
    insert('crm_categories', ['name' => $n, 'icon' => $i, 'kind' => $k, 'sla_hours' => $sla, 'sort_order' => $o]);

/* ---------------- lease template ---------------- */
insert('prpt_doc_templates', ['code' => 'lease_residential', 'name' => 'Residential tenancy agreement', 'doc_type' => 'lease', 'usage_class' => 'residential', 'is_default' => 1, 'body_html' => <<<HTML
<h1 style="text-align:center">TENANCY AGREEMENT</h1>
<p style="text-align:center"><b>Agreement No. {{lease_no}}</b> · Dated {{date}}</p>
<h3>1. Parties</h3>
<p>This agreement is made between <b>{{owner_names}}</b> ("the Landlord"), represented by its managing agent <b>{{company_name}}</b> of {{company_address}} ("the Agent"), and <b>{{tenant_names}}</b> (ID/Passport {{tenant_ids}}) ("the Tenant").</p>
<h3>2. Premises</h3>
<p>Unit <b>{{unit_no}}</b> ({{unit_type}}, {{bedrooms}} bedroom(s)), {{block}}, <b>{{property_name}}</b>, {{property_address}} (LR No. {{lr_number}}), for {{usage}} use only.</p>
<h3>3. Term</h3>
<p>The tenancy commences on <b>{{start_date}}</b> and runs {{term}}, ending <b>{{end_date}}</b>, unless terminated by either party giving <b>{{notice_days}} days'</b> written notice.</p>
<h3>4. Rent and charges</h3>
<p>Monthly rent is <b>{{rent_amount}}</b> ({{rent_words}}), invoiced on the {{billing_day}} and payable on or before the <b>{{due_day}}</b> day of each month via M-Pesa Paybill <b>{{paybill}}</b>, account <b>{{account_ref}}</b>. Rent escalation: {{escalation}}.</p>
{{charges_table}}
<h3>5. Deposit</h3>
<p>The Tenant has paid / shall pay a refundable deposit of <b>{{deposit}}</b>, held by the Agent and refundable within 30 days of vacant possession, less the cost of any damage beyond fair wear and tear and any outstanding charges.</p>
<h3>6. Tenant's obligations</h3>
<ol><li>Pay rent and all charges on time; late payments may attract penalties.</li><li>Keep the premises clean and in good repair, and report defects promptly through the self-service portal.</li>
<li>Not sublet, assign or make structural alterations without written consent.</li><li>Observe the house rules and not cause nuisance to neighbours.</li><li>Allow the Agent access for inspection or repairs on reasonable notice.</li></ol>
<h3>7. Landlord's obligations</h3>
<ol><li>Allow quiet enjoyment of the premises.</li><li>Keep the structure, roof and common areas in good repair.</li><li>Insure the building.</li></ol>
<h3>8. Termination</h3>
<p>Either party may terminate by notice as above. On termination the Tenant shall hand over the premises in the condition recorded at the move-in inspection.</p>
<h3>9. Governing law</h3><p>This agreement is governed by the laws of Kenya.</p>
<table class="doc-sign" width="100%"><tr><td><br><br>______________________<br>Landlord / Agent</td><td><br><br>______________________<br>Tenant: {{tenant_names}}</td></tr></table>
HTML]);
insert('prpt_doc_templates', ['code' => 'lease_commercial', 'name' => 'Commercial lease', 'doc_type' => 'lease', 'usage_class' => 'commercial', 'is_default' => 1, 'body_html' => <<<HTML
<h1 style="text-align:center">COMMERCIAL LEASE</h1><p style="text-align:center"><b>{{lease_no}}</b> · {{date}}</p>
<p><b>Landlord:</b> {{owner_names}} through {{company_name}}. <b>Lessee:</b> {{tenant_names}}.</p>
<p><b>Premises:</b> {{unit_no}}, {{property_name}}, {{property_address}} — for commercial use.</p>
<p><b>Term:</b> from {{start_date}} to {{end_date}} ({{term}}). <b>Rent:</b> {{rent_amount}} per month, due on the {{due_day}}, escalating {{escalation}}. <b>Deposit:</b> {{deposit}}.</p>
{{charges_table}}
<p>The Lessee shall obtain all business permits, keep the premises insured for contents, and comply with the property's rules. Notice period: {{notice_days}} days.</p>
<table class="doc-sign" width="100%"><tr><td><br><br>______________________<br>Landlord / Agent</td><td><br><br>______________________<br>Lessee</td></tr></table>
HTML]);
