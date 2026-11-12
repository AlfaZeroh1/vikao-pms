<?php
$title = 'Preventive maintenance';
$subtitle = 'Recurring jobs (lifts, pumps, fumigation, generator service…) — tickets are raised automatically on the due date';
$due = (int)val("SELECT COUNT(*) FROM crm_maintenance_schedules WHERE is_active = 1 AND next_due_date <= CURDATE()");
$actions = '<button class="vk-btn-accent" data-action="' . e(url('api.php', ['r' => 'crm/run-maintenance'])) . '" data-confirm="Raise tickets for every schedule that is due now?" data-icon="question"><i class="fa-solid fa-play"></i>Raise due tickets' . ($due ? ' (' . $due . ')' : '') . '</button>';
echo render_crud('maintenance', [], ['title' => 'Schedules', 'empty' => 'Add schedules like "Lift service every 90 days".']);
