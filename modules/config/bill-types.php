<?php
$title = 'Bill types & tariffs';
$subtitle = 'Rent, service charge, water, electricity, garbage, gas… — how each is billed, who it bills by default, and where the money belongs';
$tab = $_GET['tab'] ?? 'types';
echo ui_tabs(['types' => ['Bill types', 'fa-tags'], 'tariffs' => ['Metered tariffs', 'fa-layer-group'], 'penalties' => ['Late penalties', 'fa-gavel'], 'taxes' => ['Taxes', 'fa-percent']], $tab);
echo match ($tab) {
    'tariffs' => '<p class="text-sm text-muted mb-4">Tiered rates for metered utilities (e.g. water 0–6 m³ at KSh 120, above 6 m³ at KSh 160). Property tiers override global ones; with no tiers the flat rate on the bill / lease is used.</p>' . render_crud('tariff_tiers', [], ['title' => 'Tariff tiers']),
    'penalties' => '<p class="text-sm text-muted mb-4">The daily cron raises a penalty invoice once per overdue invoice after the grace period.</p>' . render_crud('penalty_rules', [], ['title' => 'Penalty rules']),
    'taxes' => render_crud('tax_rates', [], ['title' => 'Tax rates']),
    default => render_crud('bill_types', [], ['title' => 'Bill types']),
};
