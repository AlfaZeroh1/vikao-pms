<?php
$title = 'Message templates';
$subtitle = 'System templates (invoices, receipts, reminders, portal activation…) and your own. Use {{placeholders}} — e.g. {{first_name}}, {{balance|money}}, {{unit_no}}.';
echo render_crud('templates', [], ['title' => 'Templates']);
