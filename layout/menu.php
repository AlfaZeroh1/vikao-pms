<?php
/** Sidebar menu + route registry (route => [label, icon, permission]). */
const PMS_MENU = [
    ['Overview', null, [
        'dashboard/index' => ['Dashboard', 'fa-gauge-high', 'dashboard.view'],
    ]],
    ['Property', 'fa-city', [
        'property/properties' => ['Properties', 'fa-building', 'property.properties.view'],
        'property/units'      => ['Units', 'fa-door-open', 'property.properties.view'],
        'property/parties'    => ['Owners & tenants', 'fa-users', 'property.parties.view'],
        'property/readings'   => ['Meter readings', 'fa-gauge', 'property.readings.capture'],
        'property/assignments'=> ['Reading assignments', 'fa-clipboard-list', 'property.readings.capture'],
        'property/field-reading' => ['Reader app', 'fa-mobile-screen', 'property.readings.capture'],
        'property/templates'  => ['Document templates', 'fa-file-signature', 'property.templates.manage'],
        'property/documents'  => ['Documents', 'fa-folder-open', 'property.documents.manage'],
    ]],
    ['Billing', 'fa-file-invoice', [
        'billing/leases'   => ['Accounts & leases', 'fa-file-contract', 'billing.leases.view'],
        'billing/invoices' => ['Invoices', 'fa-file-invoice', 'billing.invoices.view'],
        'billing/runs'     => ['Billing runs', 'fa-wand-magic-sparkles', 'billing.runs.execute'],
        'billing/arrears'  => ['Arrears', 'fa-triangle-exclamation', 'billing.arrears.view'],
        'billing/deposits' => ['Deposits', 'fa-vault', 'billing.deposits.manage'],
    ]],
    ['Finance', 'fa-sack-dollar', [
        'finance/payments'    => ['Payments', 'fa-money-bill-transfer', 'finance.payments.view'],
        'finance/consume'     => ['Consume payment', 'fa-arrows-split-up-and-left', 'finance.allocate'],
        'finance/gateways'    => ['M-Pesa · Co-op · Stripe', 'fa-tower-broadcast', 'finance.gateways.view'],
        'finance/expenses'    => ['Expenses', 'fa-receipt', 'finance.expenses.manage'],
        'finance/remittances' => ['Owner remittances', 'fa-hand-holding-dollar', 'finance.remittances.manage'],
        'finance/statements'  => ['Statements', 'fa-file-lines', 'finance.statements.view'],
        'finance/accounts'    => ['Collection accounts', 'fa-building-columns', 'finance.accounts.manage'],
        'finance/quickbooks'  => ['QuickBooks export', 'fa-file-export', 'finance.qb.export'],
    ]],
    ['Communication', 'fa-paper-plane', [
        'comms/compose'   => ['Send message', 'fa-pen-to-square', 'comms.send'],
        'comms/campaigns' => ['Campaigns', 'fa-bullhorn', 'comms.send'],
        'comms/templates' => ['Templates', 'fa-envelope-open-text', 'comms.templates.manage'],
        'comms/reminders' => ['Reminders', 'fa-bell', 'comms.templates.manage'],
        'comms/log'       => ['Message log', 'fa-list-check', 'comms.log.view'],
    ]],
    ['Sales', 'fa-handshake', [
        'sales/pipeline' => ['Pipeline', 'fa-chart-simple', 'sales.leads.manage'],
        'sales/leads'    => ['Leads', 'fa-user-plus', 'sales.leads.manage'],
        'sales/deals'    => ['Deals & commission', 'fa-file-signature', 'sales.deals.manage'],
    ]],
    ['CRM', 'fa-headset', [
        'crm/tickets'     => ['Tickets', 'fa-ticket', 'crm.tickets.view'],
        'crm/maintenance' => ['Preventive maintenance', 'fa-screwdriver-wrench', 'crm.maintenance.manage'],
        'crm/inspections' => ['Inspections', 'fa-clipboard-check', 'crm.maintenance.manage'],
        'crm/vendors'     => ['Vendors', 'fa-people-carry-box', 'crm.maintenance.manage'],
    ]],
    ['Short stay', 'fa-bed', [
        'shortstay/calendar'     => ['Calendar', 'fa-calendar-days', 'shortstay.view'],
        'shortstay/bookings'     => ['Bookings', 'fa-suitcase-rolling', 'shortstay.view'],
        'shortstay/listings'     => ['Listings & rates', 'fa-house-flag', 'shortstay.manage'],
        'shortstay/housekeeping' => ['Housekeeping', 'fa-broom', 'shortstay.view'],
    ]],
    ['Ecommerce', 'fa-store', [
        'ecommerce/index'    => ['Marketplace overview', 'fa-chart-line', 'ecommerce.view'],
        'ecommerce/listings' => ['Ad moderation', 'fa-list-check', 'ecommerce.moderate'],
        'ecommerce/accounts' => ['Accounts', 'fa-user-group', 'ecommerce.accounts'],
        'ecommerce/orders'   => ['Orders & payments', 'fa-bag-shopping', 'ecommerce.view'],
        'ecommerce/push'     => ['Post official listing', 'fa-crown', 'ecommerce.official'],
        'ecommerce/api'      => ['Marketplace API', 'fa-plug', 'ecommerce.integrations'],
    ]],
    ['Reports', 'fa-chart-pie', [
        'reports/index'    => ['Report centre', 'fa-chart-pie', 'reports.view'],
        'reports/insights' => ['AI insights', 'fa-wand-magic-sparkles', 'reports.view'],
    ]],
    ['Administration', 'fa-user-shield', [
        'admin/users'  => ['Staff users', 'fa-user-gear', 'admin.users.view'],
        'admin/roles'  => ['Roles & permissions', 'fa-key', 'admin.roles.manage'],
        'admin/online' => ['Who is online', 'fa-signal', 'admin.online.view'],
        'admin/audit'  => ['Audit trail', 'fa-clock-rotate-left', 'admin.audit.view'],
    ]],
    ['Configuration', 'fa-sliders', [
        'config/settings'     => ['Settings', 'fa-gear', 'config.settings.manage'],
        'config/bill-types'   => ['Bill types & tariffs', 'fa-tags', 'config.billtypes.manage'],
        'config/lists'        => ['Lists & categories', 'fa-list', 'config.lookups.manage'],
        'config/integrations' => ['API integrations', 'fa-plug-circle-bolt', 'config.integrations.manage'],
    ]],
];
/** Detail pages not in the sidebar, with their permission */
const PMS_ROUTES_EXTRA = [
    'property/property' => 'property.properties.view', 'property/unit' => 'property.properties.view', 'property/party' => 'property.parties.view',
    'billing/lease' => 'billing.leases.view', 'billing/lease-new' => 'billing.leases.manage', 'billing/invoice' => 'billing.invoices.view', 'billing/invoice-edit' => 'billing.invoices.create',
    'finance/remittance' => 'finance.remittances.manage', 'finance/statement' => 'finance.statements.view', 'finance/payment' => 'finance.payments.view',
    'crm/ticket' => 'crm.tickets.view', 'sales/lead' => 'sales.leads.manage', 'shortstay/booking' => 'shortstay.view', 'shortstay/listing' => 'shortstay.manage',
    'reports/view' => 'reports.view', 'comms/campaign' => 'comms.send', 'dashboard/profile' => 'dashboard.view', 'dashboard/notifications' => 'dashboard.view', 'dashboard/search' => 'dashboard.view',
    'admin/user' => 'admin.users.manage', 'property/document' => 'property.properties.view',
];
function route_meta(string $r): ?array {
    foreach (PMS_MENU as [$sec, $icon, $items]) if (isset($items[$r])) return ['label' => $items[$r][0], 'icon' => $items[$r][1], 'perm' => $items[$r][2], 'section' => $sec];
    return isset(PMS_ROUTES_EXTRA[$r]) ? ['label' => null, 'icon' => null, 'perm' => PMS_ROUTES_EXTRA[$r], 'section' => ucfirst(explode('/', $r)[0])] : null;
}
