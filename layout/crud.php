<?php
/**
 * Generic CRUD for configuration-style entities. One registry entry gives you a list,
 * an add/edit modal, validation, audit trail and delete — used across many modules.
 */
function crud_entities(): array {
    static $E = null; if ($E) return $E;
    $props = fn() => array_column(properties_for_select(), 'name', 'id');
    $bts = fn() => array_column(bill_types_for_select(), 'name', 'id');
    $yes = ['1' => 'Yes', '0' => 'No'];
    $E = [
        'bill_types' => ['table' => 'cfg_bill_types', 'title' => 'Bill type', 'perm' => 'config.billtypes.manage', 'icon' => 'fa-tags', 'order' => 'sort_order, name',
            'columns' => ['name' => 'Name', 'code' => 'Code', 'category' => 'Category', 'billing_method' => 'Method', 'frequency' => 'Frequency', 'default_bill_to' => 'Bills to', 'remit_to' => 'Money belongs to', 'is_active' => 'Active'],
            'fields' => ['name' => ['Name', 'text', null, true], 'code' => ['Code', 'text', null, true, 'UPPERCASE, used in exports & agreements'],
                'category' => ['Category', 'select', ['rent' => 'Rent', 'service_charge' => 'Service charge', 'utility' => 'Utility', 'deposit' => 'Deposit', 'penalty' => 'Penalty', 'short_stay' => 'Short stay', 'sale' => 'Sale', 'other' => 'Other'], true],
                'billing_method' => ['Billing method', 'select', ['fixed' => 'Fixed amount', 'metered' => 'Metered (readings)', 'percentage' => 'Percentage', 'one_off' => 'One-off'], true],
                'frequency' => ['Frequency', 'select', ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'semi_annual' => 'Semi-annual', 'annual' => 'Annual', 'one_off' => 'One-off', 'per_booking' => 'Per booking'], true],
                'default_amount' => ['Default amount (KSh)', 'money'], 'default_unit_rate' => ['Rate per unit (metered)', 'money'], 'unit_label' => ['Unit label', 'text', null, false, 'm³, kWh, kg'],
                'minimum_charge' => ['Minimum charge', 'money'], 'default_bill_to' => ['Default bill to', 'select', ['tenant' => 'Tenant', 'owner' => 'Home owner'], true],
                'remit_to' => ['Money belongs to', 'select', ['owner' => 'Owner (remitted)', 'agency' => 'Agency', 'property_fund' => 'Property / service-charge fund', 'provider' => 'Utility provider'], true],
                'is_refundable' => ['Refundable (deposit)', 'bool'], 'allocation_priority' => ['Allocation priority', 'number', null, false, 'Lower is paid first when auto-allocating'],
                'tax_rate_id' => ['Tax', 'select', fn() => array_column(all("SELECT id, name FROM cfg_tax_rates"), 'name', 'id')],
                'qb_item' => ['QuickBooks item', 'text'], 'qb_income_account' => ['QuickBooks income account', 'text'], 'icon' => ['Icon (Font Awesome)', 'text'], 'color_hex' => ['Colour', 'color'], 'is_active' => ['Active', 'bool'], 'sort_order' => ['Sort order', 'number']],
            'defaults' => ['is_active' => 1, 'allocation_priority' => 50, 'color_hex' => '#64748B', 'icon' => 'fa-file-invoice'], 'protect' => 'is_system'],
        'tariff_tiers' => ['table' => 'cfg_tariff_tiers', 'title' => 'Tariff tier', 'perm' => 'config.billtypes.manage', 'icon' => 'fa-layer-group', 'order' => 'bill_type_id, property_id, from_units',
            'columns' => ['bill_type_id' => 'Bill', 'property_id' => 'Property', 'from_units' => 'From', 'to_units' => 'To', 'rate' => 'Rate'],
            'fields' => ['bill_type_id' => ['Bill type', 'select', fn() => array_column(all("SELECT id, name FROM cfg_bill_types WHERE billing_method = 'metered'"), 'name', 'id'), true],
                'property_id' => ['Property (blank = all)', 'select', $props], 'from_units' => ['From units', 'number', null, true], 'to_units' => ['To units (blank = ∞)', 'number'], 'rate' => ['Rate per unit', 'money', null, true]]],
        'penalty_rules' => ['table' => 'cfg_penalty_rules', 'title' => 'Penalty rule', 'perm' => 'config.billtypes.manage', 'icon' => 'fa-gavel', 'order' => 'name',
            'columns' => ['name' => 'Rule', 'property_id' => 'Property', 'grace_days' => 'Grace days', 'penalty_type' => 'Type', 'penalty_value' => 'Value', 'is_active' => 'Active'],
            'fields' => ['name' => ['Name', 'text', null, true], 'property_id' => ['Property (blank = all)', 'select', $props], 'applies_to_bill_type_id' => ['Bill type (blank = any)', 'select', $bts],
                'grace_days' => ['Grace days after due date', 'number', null, true], 'penalty_type' => ['Type', 'select', ['percentage' => '% of balance', 'fixed' => 'Fixed amount'], true],
                'penalty_value' => ['Value', 'number', null, true], 'max_penalty' => ['Cap (KSh)', 'money'], 'is_active' => ['Active', 'bool']], 'defaults' => ['grace_days' => 5, 'is_active' => 1]],
        'tax_rates' => ['table' => 'cfg_tax_rates', 'title' => 'Tax rate', 'perm' => 'config.billtypes.manage', 'icon' => 'fa-percent', 'order' => 'name',
            'columns' => ['code' => 'Code', 'name' => 'Name', 'rate_pct' => 'Rate %', 'is_active' => 'Active'],
            'fields' => ['code' => ['Code', 'text', null, true], 'name' => ['Name', 'text', null, true], 'rate_pct' => ['Rate %', 'number', null, true], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1]],
        'lookups' => ['table' => 'cfg_lookups', 'title' => 'List item', 'perm' => 'config.lookups.manage', 'icon' => 'fa-list', 'order' => 'list_key, sort_order, label',
            'columns' => ['list_key' => 'List', 'code' => 'Code', 'label' => 'Label', 'is_active' => 'Active'],
            'fields' => ['list_key' => ['List', 'select', ['unit_type' => 'Unit types'], true], 'code' => ['Code', 'text', null, true], 'label' => ['Label', 'text', null, true], 'icon' => ['Icon', 'text'], 'sort_order' => ['Sort', 'number'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1, 'list_key' => 'unit_type']],
        'expense_categories' => ['table' => 'fin_expense_categories', 'title' => 'Expense category', 'perm' => 'finance.expenses.manage', 'icon' => 'fa-tags', 'order' => 'name',
            'columns' => ['name' => 'Name', 'code' => 'Code', 'default_chargeable_to' => 'Default charged to', 'qb_expense_account' => 'QuickBooks account', 'is_active' => 'Active'],
            'fields' => ['name' => ['Name', 'text', null, true], 'code' => ['Code', 'text', null, true], 'default_chargeable_to' => ['Default charged to', 'select', ['owner' => 'Owner', 'agency' => 'Agency', 'property_fund' => 'Property fund', 'tenant' => 'Tenant'], true],
                'qb_expense_account' => ['QuickBooks expense account', 'text'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1]],
        'vendors' => ['table' => 'fin_vendors', 'title' => 'Vendor', 'perm' => 'crm.maintenance.manage', 'icon' => 'fa-people-carry-box', 'order' => 'name',
            'columns' => ['name' => 'Vendor', 'service' => 'Service', 'phone' => 'Phone', 'email' => 'Email', 'rating' => 'Rating', 'is_active' => 'Active'],
            'fields' => ['name' => ['Name', 'text', null, true], 'service' => ['Service / trade', 'text'], 'phone' => ['Phone', 'text'], 'email' => ['Email', 'text'], 'kra_pin' => ['KRA PIN', 'text'],
                'payment_details' => ['Payment details', 'text'], 'rating' => ['Rating (1-5)', 'number'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1]],
        'crm_categories' => ['table' => 'crm_categories', 'title' => 'Ticket category', 'perm' => 'crm.tickets.manage', 'icon' => 'fa-ticket', 'order' => 'sort_order, name',
            'columns' => ['name' => 'Category', 'kind' => 'Kind', 'sla_hours' => 'SLA (h)', 'default_assignee' => 'Default assignee', 'is_active' => 'Active'],
            'fields' => ['name' => ['Name', 'text', null, true], 'kind' => ['Kind', 'select', ['maintenance' => 'Maintenance', 'complaint' => 'Complaint', 'inquiry' => 'Inquiry', 'billing' => 'Billing', 'security' => 'Security', 'request' => 'Request', 'feedback' => 'Feedback'], true],
                'sla_hours' => ['SLA hours', 'number', null, true], 'default_assignee' => ['Default assignee', 'select', fn() => array_column(staff_for_select(), 'name', 'id')], 'icon' => ['Icon', 'text'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1, 'sla_hours' => 48, 'icon' => 'fa-ticket']],
        'sls_stages' => ['table' => 'sls_stages', 'title' => 'Pipeline stage', 'perm' => 'sales.deals.manage', 'icon' => 'fa-chart-simple', 'order' => 'sort_order',
            'columns' => ['name' => 'Stage', 'probability' => 'Probability %', 'is_won' => 'Won', 'is_lost' => 'Lost', 'sort_order' => 'Order'],
            'fields' => ['name' => ['Name', 'text', null, true], 'probability' => ['Win probability %', 'number', null, true], 'color_hex' => ['Colour', 'color'], 'is_won' => ['Marks won', 'bool'], 'is_lost' => ['Marks lost', 'bool'], 'sort_order' => ['Order', 'number']]],
        'fin_accounts' => ['table' => 'fin_accounts', 'title' => 'Collection account', 'perm' => 'finance.accounts.manage', 'icon' => 'fa-building-columns', 'order' => 'is_master DESC, name',
            'columns' => ['name' => 'Account', 'account_type' => 'Type', 'number' => 'Number', 'holder' => 'Held by', 'is_master' => 'Vikao master', 'integration_code' => 'Live feed', 'is_active' => 'Active'],
            'fields' => ['code' => ['Code', 'text', null, true], 'name' => ['Name', 'text', null, true], 'account_type' => ['Type', 'select', ['mpesa_paybill' => 'M-Pesa paybill', 'mpesa_till' => 'M-Pesa till', 'bank' => 'Bank', 'stripe' => 'Stripe', 'cash' => 'Cash', 'cheque' => 'Cheque', 'other' => 'Other'], true],
                'provider' => ['Provider', 'text'], 'number' => ['Paybill / account number', 'text'], 'holder' => ['Held by', 'select', ['agency' => 'Vikao (agency)', 'owner' => 'Home owner', 'property' => 'Property / association'], true],
                'owner_party_id' => ['Owner (if owner-held)', 'party'], 'is_master' => ['Vikao master account', 'bool'], 'integration_code' => ['Integration (live feed)', 'select', fn() => array_column(all("SELECT code, name FROM api_integrations WHERE provider IN ('mpesa','coop','stripe')"), 'name', 'code')],
                'qb_account' => ['QuickBooks bank account', 'text'], 'opening_balance' => ['Opening balance', 'money'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1, 'holder' => 'agency']],
        'templates' => ['table' => 'com_templates', 'title' => 'Message template', 'perm' => 'comms.templates.manage', 'icon' => 'fa-envelope-open-text', 'order' => 'category, name',
            'columns' => ['name' => 'Template', 'code' => 'Code', 'category' => 'Category', 'channel' => 'Channel', 'subject' => 'Subject'],
            'fields' => ['name' => ['Name', 'text', null, true], 'code' => ['Code', 'text', null, true, 'Used by the system — e.g. invoice_issued'],
                'category' => ['Category', 'select', ['billing' => 'Billing', 'reminder' => 'Reminder', 'crm' => 'CRM', 'lease' => 'Lease', 'portal' => 'Portal', 'announcement' => 'Announcement', 'sales' => 'Sales', 'short_stay' => 'Short stay', 'general' => 'General'], true],
                'channel' => ['Channel', 'select', ['email' => 'Email', 'sms' => 'SMS', 'both' => 'Email + SMS', 'portal' => 'Portal only'], true], 'subject' => ['Subject', 'text'],
                'body_email' => ['Email / portal body (HTML, {{placeholders}})', 'html'], 'body_sms' => ['SMS body (160 chars per part)', 'textarea']], 'protect' => 'is_system'],
        'reminder_rules' => ['table' => 'com_reminder_rules', 'title' => 'Reminder rule', 'perm' => 'comms.templates.manage', 'icon' => 'fa-bell', 'order' => 'trigger_type, offset_days',
            'columns' => ['name' => 'Rule', 'trigger_type' => 'Trigger', 'offset_days' => 'Offset (days)', 'channel' => 'Channel', 'is_active' => 'Active', 'last_run_at' => 'Last run'],
            'fields' => ['name' => ['Name', 'text', null, true], 'trigger_type' => ['Trigger', 'select', ['invoice_due' => 'Invoice due', 'invoice_overdue' => 'Invoice overdue', 'lease_expiry' => 'Lease expiry', 'booking_checkin' => 'Guest check-in'], true],
                'offset_days' => ['Offset days (− before, + after)', 'number', null, true], 'template_id' => ['Template', 'select', fn() => array_column(all("SELECT id, name FROM com_templates ORDER BY name"), 'name', 'id'), true],
                'channel' => ['Channel', 'select', ['email' => 'Email', 'sms' => 'SMS', 'both' => 'Email + SMS', 'portal' => 'Portal'], true], 'min_amount' => ['Only if balance ≥', 'money'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1]],
        'amenities' => ['table' => 'prpt_amenities', 'title' => 'Amenity', 'perm' => 'property.properties.manage', 'icon' => 'fa-person-swimming', 'order' => 'name', 'parent' => 'property_id',
            'columns' => ['name' => 'Amenity', 'access' => 'Access', 'schedule' => 'Schedule', 'fee' => 'Fee'],
            'fields' => ['name' => ['Name', 'text', null, true], 'icon' => ['Icon', 'text'], 'description' => ['Description', 'text'], 'access' => ['Who can use it', 'select', ['all' => 'Everyone', 'owners' => 'Owners', 'tenants' => 'Tenants', 'booking_required' => 'Booking required', 'paid' => 'Paid'], true],
                'schedule' => ['Schedule', 'text', null, false, 'e.g. Daily 6am – 10pm'], 'fee' => ['Fee', 'money']], 'defaults' => ['access' => 'all', 'icon' => 'fa-star']],
        'parking' => ['table' => 'prpt_parking_slots', 'title' => 'Parking slot', 'perm' => 'property.properties.manage', 'icon' => 'fa-square-parking', 'order' => 'code', 'parent' => 'property_id',
            'columns' => ['code' => 'Slot', 'slot_type' => 'Type', 'unit_id' => 'Unit', 'party_id' => 'Allocated to', 'vehicle_reg' => 'Vehicle', 'status' => 'Status'],
            'fields' => ['code' => ['Slot code', 'text', null, true], 'slot_type' => ['Type', 'select', ['covered' => 'Covered', 'open' => 'Open', 'basement' => 'Basement', 'visitor' => 'Visitor', 'motorbike' => 'Motorbike', 'ev' => 'EV charging'], true],
                'unit_id' => ['Allocated to unit', 'unit'], 'party_id' => ['Allocated to person', 'party'], 'vehicle_reg' => ['Vehicle reg', 'text'], 'monthly_fee' => ['Monthly fee', 'money'],
                'status' => ['Status', 'select', ['available' => 'Available', 'allocated' => 'Allocated', 'reserved' => 'Reserved', 'inactive' => 'Inactive'], true]], 'defaults' => ['status' => 'available', 'slot_type' => 'open']],
        'notices' => ['table' => 'prpt_notices', 'title' => 'Rule / notice', 'perm' => 'property.properties.manage', 'icon' => 'fa-bullhorn', 'order' => 'is_pinned DESC, created_at DESC', 'parent' => 'property_id',
            'columns' => ['title' => 'Title', 'kind' => 'Kind', 'audience' => 'Audience', 'is_pinned' => 'Pinned', 'ends_at' => 'Until'],
            'fields' => ['title' => ['Title', 'text', null, true], 'kind' => ['Kind', 'select', ['rule' => 'House rule', 'schedule' => 'Schedule', 'notice' => 'Notice', 'emergency' => 'Emergency'], true], 'body' => ['Details', 'textarea', null, true],
                'audience' => ['Audience', 'select', ['all' => 'Everyone', 'owners' => 'Owners', 'tenants' => 'Tenants'], true], 'starts_at' => ['Show from', 'datetime-local'], 'ends_at' => ['Show until', 'datetime-local'], 'is_pinned' => ['Pin to top', 'bool']], 'defaults' => ['kind' => 'notice', 'audience' => 'all']],
        'property_charges' => ['table' => 'prpt_property_charges', 'title' => 'Default charge', 'perm' => 'property.properties.manage', 'icon' => 'fa-receipt', 'order' => 'bill_type_id', 'parent' => 'property_id',
            'columns' => ['bill_type_id' => 'Bill', 'amount' => 'Amount', 'unit_rate' => 'Rate (metered)', 'bill_to' => 'Bill to', 'applies_to' => 'Applies to', 'is_mandatory' => 'Mandatory'],
            'fields' => ['bill_type_id' => ['Bill type', 'select', $bts, true], 'amount' => ['Fixed amount', 'money'], 'unit_rate' => ['Rate per unit (metered)', 'money'], 'bill_to' => ['Bill to (blank = bill-type default)', 'select', ['tenant' => 'Tenant', 'owner' => 'Owner']],
                'applies_to' => ['Applies to', 'select', ['all' => 'All units', 'residential' => 'Residential units', 'commercial' => 'Commercial units'], true], 'is_mandatory' => ['Mandatory on new accounts', 'bool']], 'defaults' => ['applies_to' => 'all', 'is_mandatory' => 1]],
        'agreements' => ['table' => 'prpt_mgmt_agreements', 'title' => 'Management agreement', 'perm' => 'property.agreements.manage', 'icon' => 'fa-handshake', 'order' => 'start_date DESC', 'parent' => 'property_id',
            'columns' => ['party_id' => 'Owner', 'fee_type' => 'Fee type', 'fee_value' => 'Fee', 'sales_commission_value' => 'Sales commission', 'start_date' => 'From', 'is_active' => 'Active'],
            'fields' => ['party_id' => ['Owner (blank = all owners in property)', 'party'], 'fee_type' => ['Management fee type', 'select', ['percentage' => '% of collections', 'fixed_per_occupied_unit' => 'Fixed per occupied unit / month', 'fixed_per_unit' => 'Fixed per unit / month', 'flat_monthly' => 'Flat monthly'], true],
                'fee_value' => ['Fee value (% or KSh)', 'number', null, true], 'fee_base' => ['Fee base', 'select', ['collected' => 'Collected', 'invoiced' => 'Invoiced'], true], 'fee_bill_types' => ['% applies to bill codes', 'text', null, false, 'CSV, e.g. RENT,PARKING'],
                'vat_on_fee' => ['Charge VAT on fee', 'bool'], 'sales_commission_type' => ['Sales commission type', 'select', ['percentage' => 'Percentage', 'fixed' => 'Fixed']], 'sales_commission_value' => ['Sales commission value', 'number'],
                'letting_fee_type' => ['Letting fee', 'select', ['none' => 'None', 'percentage_of_rent' => '% of first month rent', 'fixed' => 'Fixed'], true], 'letting_fee_value' => ['Letting fee value', 'number'],
                'remittance_day' => ['Remittance day of month', 'number', null, true], 'start_date' => ['Start date', 'date', null, true], 'end_date' => ['End date', 'date'], 'notes' => ['Notes', 'textarea'], 'is_active' => ['Active', 'bool']],
            'defaults' => ['fee_type' => 'percentage', 'fee_value' => 10, 'fee_base' => 'collected', 'fee_bill_types' => 'RENT', 'vat_on_fee' => 1, 'letting_fee_type' => 'none', 'remittance_day' => 10, 'is_active' => 1]],
        'meters' => ['table' => 'prpt_meters', 'title' => 'Meter', 'perm' => 'property.meters.manage', 'icon' => 'fa-gauge', 'order' => 'serial_no', 'parent' => 'property_id',
            'columns' => ['serial_no' => 'Serial', 'unit_id' => 'Unit', 'bill_type_id' => 'Utility', 'meter_type' => 'Type', 'initial_reading' => 'Initial', 'status' => 'Status'],
            'fields' => ['serial_no' => ['Serial number', 'text', null, true], 'unit_id' => ['Unit (blank = common/bulk meter)', 'unit'], 'bill_type_id' => ['Utility', 'select', fn() => array_column(all("SELECT id, name FROM cfg_bill_types WHERE billing_method = 'metered'"), 'name', 'id'), true],
                'meter_type' => ['Type', 'select', ['postpaid' => 'Postpaid', 'prepaid' => 'Prepaid', 'smart' => 'Smart'], true], 'multiplier' => ['Multiplier', 'number'], 'initial_reading' => ['Initial reading', 'number', null, true],
                'install_date' => ['Installed', 'date'], 'status' => ['Status', 'select', ['active' => 'Active', 'faulty' => 'Faulty', 'replaced' => 'Replaced', 'removed' => 'Removed'], true]], 'defaults' => ['meter_type' => 'postpaid', 'multiplier' => 1, 'initial_reading' => 0, 'status' => 'active']],
        'maintenance' => ['table' => 'crm_maintenance_schedules', 'title' => 'Maintenance schedule', 'perm' => 'crm.maintenance.manage', 'icon' => 'fa-screwdriver-wrench', 'order' => 'next_due_date',
            'columns' => ['title' => 'Task', 'property_id' => 'Property', 'every_days' => 'Every (days)', 'next_due_date' => 'Next due', 'vendor_id' => 'Vendor', 'is_active' => 'Active'],
            'fields' => ['title' => ['Task', 'text', null, true], 'property_id' => ['Property', 'select', $props, true], 'category_id' => ['Category', 'select', fn() => array_column(all("SELECT id, name FROM crm_categories"), 'name', 'id'), true],
                'vendor_id' => ['Vendor', 'select', fn() => array_column(all("SELECT id, name FROM fin_vendors WHERE is_active = 1"), 'name', 'id')], 'every_days' => ['Repeat every (days)', 'number', null, true],
                'next_due_date' => ['Next due', 'date', null, true], 'estimated_cost' => ['Estimated cost', 'money'], 'is_active' => ['Active', 'bool']], 'defaults' => ['every_days' => 90, 'is_active' => 1]],
        'sst_rates' => ['table' => 'sst_rate_rules', 'title' => 'Seasonal rate', 'perm' => 'shortstay.manage', 'icon' => 'fa-sun', 'order' => 'start_date', 'parent' => 'listing_id',
            'columns' => ['name' => 'Season', 'start_date' => 'From', 'end_date' => 'To', 'nightly_rate' => 'Nightly', 'min_nights' => 'Min nights'],
            'fields' => ['name' => ['Name', 'text', null, true], 'start_date' => ['From', 'date', null, true], 'end_date' => ['To', 'date', null, true], 'nightly_rate' => ['Nightly rate', 'money', null, true], 'min_nights' => ['Min nights', 'number']]],
        'sst_channels' => ['table' => 'sst_channels', 'title' => 'Channel (OTA)', 'perm' => 'shortstay.manage', 'icon' => 'fa-globe', 'order' => 'channel', 'parent' => 'listing_id',
            'columns' => ['channel' => 'Channel', 'commission_pct' => 'Commission %', 'last_synced_at' => 'Last sync', 'last_sync_status' => 'Status', 'is_active' => 'Active'],
            'fields' => ['channel' => ['Channel', 'select', ['airbnb' => 'Airbnb', 'booking_com' => 'Booking.com', 'vrbo' => 'Vrbo', 'expedia' => 'Expedia', 'vikao' => 'Vikao marketplace', 'other' => 'Other'], true],
                'ical_import_url' => ['iCal import URL (from the OTA)', 'text'], 'commission_pct' => ['Commission %', 'number'], 'is_active' => ['Active', 'bool']], 'defaults' => ['is_active' => 1]],
        'blocks' => ['table' => 'prpt_blocks', 'title' => 'Block', 'perm' => 'property.properties.manage', 'icon' => 'fa-building', 'order' => 'sort_order, code', 'parent' => 'property_id',
            'columns' => ['code' => 'Code', 'name' => 'Name', 'floors_count' => 'Floors'],
            'fields' => ['code' => ['Code', 'text', null, true, 'e.g. A'], 'name' => ['Name', 'text', null, true], 'floors_count' => ['Number of floors', 'number'], 'sort_order' => ['Order', 'number']]],
    ];
    return $E;
}
function crud_get(string $key): array { $e = crud_entities()[$key] ?? null; if (!$e) throw new RuntimeException('Unknown entity.'); return $e; }

/** Render the display value of a column */
function crud_cell(array $e, string $col, $v): string {
    $f = $e['fields'][$col] ?? null;
    if ($v === null || $v === '') return '<span class="text-muted">—</span>';
    if ($f && $f[1] === 'bool') return $v ? '<i class="fa-solid fa-circle-check text-emerald-500"></i>' : '<i class="fa-regular fa-circle text-muted"></i>';
    if ($f && $f[1] === 'money') return money($v, false);
    if ($f && $f[1] === 'party') return e((string)val("SELECT display_name FROM prpt_parties WHERE id = ?", [$v]));
    if ($f && $f[1] === 'unit') return e((string)val("SELECT unit_no FROM prpt_units WHERE id = ?", [$v]));
    if ($f && $f[1] === 'color') return '<span class="inline-block h-4 w-4 rounded" style="background:' . e($v) . '"></span>';
    if ($f && $f[1] === 'select') { $opts = is_callable($f[2]) ? $f[2]() : $f[2]; return e($opts[$v] ?? ucwords(str_replace('_', ' ', (string)$v))); }
    if ($col === 'status') return pill((string)$v);
    return e(excerpt((string)$v, 60));
}
function crud_field_html(string $name, array $f, $value): string {
    [$label, $type, $opts, $req, $help] = array_pad($f, 5, null);
    $r = $req ? ' required' : '';
    $ctl = match ($type) {
        'select' => '<select name="' . $name . '" class="vk-select"' . $r . '>' . ui_kv_options(is_callable($opts) ? $opts() : $opts, $value, $req ? null : '—') . '</select>',
        'bool' => '<label class="vk-switch mt-1"><input type="hidden" name="' . $name . '" value="0"><input type="checkbox" name="' . $name . '" value="1"' . ($value ? ' checked' : '') . '>' . e($label) . '</label>',
        'textarea' => '<textarea name="' . $name . '" rows="3" class="vk-textarea"' . $r . '>' . e($value) . '</textarea>',
        'html' => '<textarea name="' . $name . '" rows="8" class="vk-textarea font-mono text-xs"' . $r . '>' . e($value) . '</textarea>',
        'money', 'number' => '<input type="number" step="any" name="' . $name . '" value="' . e($value) . '" class="vk-input"' . $r . '>',
        'color' => '<input type="color" name="' . $name . '" value="' . e($value ?: '#64748B') . '" class="h-11 w-20 rounded-xl border border-line">',
        'party' => ui_party_picker($name, $value ? (int)$value : null),
        'unit' => ui_unit_picker($name, $value ? (int)$value : null),
        default => '<input type="' . e($type) . '" name="' . $name . '" value="' . e($type === 'datetime-local' && $value ? date('Y-m-d\TH:i', strtotime((string)$value)) : $value) . '" class="vk-input"' . $r . '>',
    };
    if ($type === 'bool') return '<div data-field-wrap>' . $ctl . ($help ? '<p class="text-xs text-muted mt-1">' . e($help) . '</p>' : '') . '</div>';
    return '<label class="block" data-field-wrap><span class="vk-label">' . e($label) . ($req ? ' <span class="text-accent">*</span>' : '') . '</span>' . $ctl . ($help ? '<span class="block text-xs text-muted mt-1">' . e($help) . '</span>' : '') . '</label>';
}

/** Render a full CRUD block (table + modal). $parent = ['property_id' => 5] for nested lists. */
function render_crud(string $key, array $parent = [], array $opt = []): string {
    $e = crud_get($key); $canEdit = can($e['perm']);
    $where = ['1=1']; $p = [];
    foreach ($parent as $k => $v) { $where[] = "`$k` = :pp_$k"; $p["pp_$k"] = $v; }
    $rows = all("SELECT * FROM `{$e['table']}` WHERE " . implode(' AND ', $where) . " ORDER BY {$e['order']} LIMIT 500", $p);
    $mid = 'crud-' . $key . '-' . substr(md5(json_encode($parent)), 0, 6);
    $fields = '';
    foreach ($parent as $k => $v) $fields .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    $fields .= '<input type="hidden" name="id" value=""><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">';
    foreach ($e['fields'] as $n => $f) { $wide = in_array($f[1], ['textarea', 'html'], true); $fields .= '<div class="' . ($wide ? 'sm:col-span-2' : '') . '">' . crud_field_html($n, $f, $e['defaults'][$n] ?? null) . '</div>'; }
    $fields .= '</div>';
    ob_start(); ?>
    <div class="vk-panel overflow-hidden" data-crud="<?= e($key) ?>">
      <div class="flex items-center gap-3 px-5 py-3.5 border-b border-line">
        <h3 class="font-bold flex items-center gap-2"><i class="fa-solid <?= e($e['icon']) ?> text-accent"></i><?= e($opt['title'] ?? ($e['title'] . 's')) ?></h3>
        <span class="text-xs text-muted"><?= count($rows) ?></span>
        <?php if ($canEdit): ?><button type="button" class="ml-auto vk-btn-accent !py-2 text-sm" data-crud-new="#<?= $mid ?>" data-title="New <?= e(strtolower($e['title'])) ?>"><i class="fa-solid fa-plus"></i>Add</button><?php endif; ?>
      </div>
      <?php if (!$rows): echo ui_empty($e['icon'], 'Nothing here yet', $opt['empty'] ?? ''); else: ?>
      <div class="overflow-x-auto"><table class="vk-table">
        <thead><tr><?php foreach ($e['columns'] as $c => $l) echo '<th>' . e($l) . '</th>'; ?><th class="w-24"></th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
          <tr><?php foreach ($e['columns'] as $c => $l) echo '<td>' . crud_cell($e, $c, $r[$c] ?? null) . '</td>'; ?>
            <td class="text-right whitespace-nowrap"><?php if ($canEdit): ?>
              <button type="button" class="vk-icon-btn !h-8 !w-8" data-crud-edit="#<?= $mid ?>" data-row='<?= e(json_encode($r)) ?>' data-title="Edit <?= e(strtolower($e['title'])) ?>" title="Edit"><i class="fa-solid fa-pen text-xs"></i></button>
              <?php if (empty($e['protect']) || empty($r[$e['protect']])): ?><button type="button" class="vk-icon-btn !h-8 !w-8 hover:!text-rose-600" data-action="<?= e(url('api.php', ['r' => 'crud/delete', 'e' => $key, 'id' => $r['id']])) ?>" data-confirm="Delete this <?= e(strtolower($e['title'])) ?>?" title="Delete"><i class="fa-regular fa-trash-can text-xs"></i></button><?php endif; ?>
            <?php endif; ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
    </div>
    <?= $canEdit ? ui_modal($mid, $e['title'], $fields, url('api.php', ['r' => 'crud/save', 'e' => $key]), 'Save', 'max-w-2xl', $e['icon']) : '' ?>
    <?php return ob_get_clean();
}
