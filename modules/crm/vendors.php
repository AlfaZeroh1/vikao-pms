<?php
$title = 'Vendors & technicians';
$subtitle = 'Plumbers, electricians, cleaners and suppliers — assign them to tickets and record their bills as expenses';
echo render_crud('vendors', [], ['title' => 'Vendors']);
$top = all("SELECT v.name, COUNT(DISTINCT t.id) jobs, COALESCE(SUM(e.amount),0) spend, AVG(t.rating) rating FROM fin_vendors v LEFT JOIN crm_tickets t ON t.vendor_id = v.id LEFT JOIN fin_expenses e ON e.vendor_id = v.id AND e.status <> 'rejected' GROUP BY v.id HAVING jobs > 0 OR spend > 0 ORDER BY spend DESC LIMIT 10");
if ($top): ?>
<div class="vk-panel overflow-hidden mt-6"><div class="px-5 py-3.5 border-b border-line font-bold">Vendor activity</div><table class="vk-table"><thead><tr><th>Vendor</th><th class="num">Jobs</th><th class="num">Spend</th><th>Customer rating</th></tr></thead><tbody>
<?php foreach ($top as $v): ?><tr><td><?= e($v['name']) ?></td><td class="num"><?= (int)$v['jobs'] ?></td><td class="num"><?= money($v['spend'], false) ?></td><td><?= $v['rating'] ? num($v['rating'], 1) . ' ★' : '—' ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php endif;
