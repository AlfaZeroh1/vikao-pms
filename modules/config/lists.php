<?php
$title = 'Lists & categories';
$tab = $_GET['tab'] ?? 'units';
echo ui_tabs(['units' => ['Unit types', 'fa-door-open'], 'expense' => ['Expense categories', 'fa-receipt'], 'tickets' => ['Ticket categories', 'fa-ticket'], 'stages' => ['Sales stages', 'fa-chart-simple']], $tab);
echo match ($tab) {
    'expense' => render_crud('expense_categories', [], ['title' => 'Expense categories']),
    'tickets' => render_crud('crm_categories', [], ['title' => 'Ticket categories']),
    'stages' => render_crud('sls_stages', [], ['title' => 'Pipeline stages']),
    default => render_crud('lookups', ['list_key' => 'unit_type'], ['title' => 'Unit types']),
};
