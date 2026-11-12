<?php
$title = 'Automatic reminders';
$subtitle = 'Rules the daily cron runs: before / after due dates, lease expiry and guest check-in';
$last = val("SELECT MAX(last_run_at) FROM com_reminder_rules");
$actions = '<button class="vk-btn-accent" data-action="' . e(url('api.php', ['r' => 'comms/run-reminders'])) . '" data-confirm="Run all active reminder rules now?" data-icon="question"><i class="fa-solid fa-play"></i>Run now</button>';
echo '<p class="text-sm text-muted mb-4"><i class="fa-regular fa-clock"></i> Last run: ' . ($last ? fdatetime($last) : 'never') . ' · Offset days: negative = before the date, positive = after. Each invoice / lease is reminded once per rule.</p>';
echo render_crud('reminder_rules', [], ['title' => 'Reminder rules']);
