<?php
require_once __DIR__ . '/_partials.php';
$l = one("SELECT l.*, s.name AS stage, s.color_hex, u.unit_no, u.sale_price, pr.name AS property, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS agent FROM sls_leads l JOIN sls_stages s ON s.id = l.stage_id LEFT JOIN prpt_units u ON u.id = l.unit_id LEFT JOIN prpt_properties pr ON pr.id = l.property_id LEFT JOIN sys_users su ON su.id = l.assigned_to WHERE l.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$l) { echo ui_empty('fa-user', 'Lead not found'); return; }
$id = (int)$l['id'];
$title = $l['full_name']; $subtitle = e($l['lead_no']) . ' · ' . e(ucfirst(str_replace('_', ' ', $l['interest']))) . ' · <span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background:' . e($l['color_hex']) . '"></span>' . e($l['stage']) . '</span>';
$crumbs = [['Leads', page('sales/leads')]];
$acts = all("SELECT a.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name,'')) AS who FROM sls_activities a LEFT JOIN sys_users u ON u.id = a.user_id WHERE a.lead_id = ? ORDER BY COALESCE(a.done_at, a.due_at) DESC, a.id DESC", [$id]);
$open = array_filter($acts, fn($a) => !$a['done_at']);
$deals = all("SELECT d.*, u.unit_no FROM sls_deals d JOIN prpt_units u ON u.id = d.unit_id WHERE d.lead_id = ? ORDER BY d.created_at DESC", [$id]);
$stages = all("SELECT * FROM sls_stages ORDER BY sort_order");
$actions = '<button class="vk-btn-ghost" data-crud-edit="#m-lead" data-row=\'' . e(json_encode($l)) . '\' data-title="Edit lead"><i class="fa-solid fa-pen"></i>Edit</button>'
  . ($l['phone'] ? '<a class="vk-btn-ghost" href="tel:' . e($l['phone']) . '"><i class="fa-solid fa-phone"></i>Call</a><a class="vk-btn-ghost" target="_blank" href="https://wa.me/' . e(ltrim((string)$l['phone'], '+')) . '"><i class="fa-brands fa-whatsapp"></i>WhatsApp</a>' : '')
  . ($l['party_id'] ? '<a class="vk-btn-ghost" href="' . e(page('property/party', ['id' => $l['party_id']])) . '"><i class="fa-solid fa-id-card"></i>Customer</a>' : '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'sales/convert-party', 'id' => $id])) . '" data-confirm="Create a customer record from this lead?" data-icon="question"><i class="fa-solid fa-user-check"></i>Convert</button>')
  . (can('sales.deals.manage') ? '<button class="vk-btn-accent" data-modal-open="#m-deal"><i class="fa-solid fa-handshake"></i>Create deal</button>' : '');
?>
<div class="flex gap-1 overflow-x-auto mb-5"><?php foreach ($stages as $s): $cur = (int)$s['id'] === (int)$l['stage_id']; ?>
  <button class="flex-1 min-w-[110px] rounded-xl px-3 py-2 text-xs font-bold border <?= $cur ? 'text-white border-transparent' : 'border-line hover:border-accent/60' ?>" style="<?= $cur ? 'background:' . e($s['color_hex'] ?: '#D97706') : '' ?>"
    data-action="<?= e(url('api.php', ['r' => 'sales/lead-stage'])) ?>" data-payload='{"id":<?= $id ?>,"stage_id":<?= $s['id'] ?>}' <?= $s['is_lost'] ? 'data-prompt="Why was this lead lost?"' : '' ?>><?= e($s['name']) ?></button><?php endforeach; ?></div>
<div class="grid grid-cols-1 xl:grid-cols-[1fr_360px] gap-6 items-start">
  <div class="space-y-6">
    <form data-ajax="<?= e(url('api.php', ['r' => 'sales/log-activity'])) ?>" class="vk-panel p-5 space-y-3">
      <input type="hidden" name="lead_id" value="<?= $id ?>">
      <div class="flex flex-wrap gap-2"><?php foreach (['call' => 'fa-phone', 'whatsapp' => 'fa-whatsapp', 'sms' => 'fa-comment-sms', 'email' => 'fa-envelope', 'meeting' => 'fa-people-group', 'site_visit' => 'fa-person-walking', 'note' => 'fa-note-sticky'] as $k => $ic): ?>
        <label class="vk-choice !py-1.5"><input type="radio" name="activity_type" value="<?= $k ?>" <?= $k === 'call' ? 'checked' : '' ?>><span class="text-xs"><i class="fa-<?= $k === 'whatsapp' ? 'brands' : 'solid' ?> <?= $ic ?>"></i><?= ucfirst(str_replace('_', ' ', $k)) ?></span></label><?php endforeach; ?></div>
      <input name="summary" class="vk-input" placeholder="What happened? e.g. Discussed 2-bed options, wants a viewing Saturday" required>
      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <?= ui_field('Outcome', ui_select('outcome', ui_kv_options(['positive' => '👍 Positive', 'neutral' => 'Neutral', 'negative' => '👎 Negative'], null, '—'))) ?>
        <?= ui_field('Follow up on', ui_input('follow_up_at', date('Y-m-d\T10:00', strtotime('+2 days')), 'datetime-local')) ?>
        <?= ui_field('Follow-up by', ui_select('follow_up_type', ui_kv_options(['call' => 'Call', 'whatsapp' => 'WhatsApp', 'meeting' => 'Meeting', 'site_visit' => 'Site visit', 'email' => 'Email']))) ?>
        <?= ui_field('Follow-up note', ui_input('follow_up_note', '', 'text', 'placeholder="optional"')) ?>
      </div>
      <div class="flex justify-end"><button class="vk-btn-accent !py-2"><i class="fa-solid fa-plus"></i>Log activity</button></div>
    </form>
    <?php if ($deals): ?><div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Deals</div><table class="vk-table"><tbody>
      <?php foreach ($deals as $d): ?><tr data-href="<?= e(page('sales/deals', ['id' => $d['id']])) ?>"><td><b><?= e($d['deal_no']) ?></b> · unit <?= e($d['unit_no']) ?></td><td class="num font-bold"><?= money($d['agreed_price']) ?></td><td><?= pill($d['status']) ?></td><td class="text-xs text-muted">commission <?= money($d['commission_amount']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
    <div class="vk-panel p-6"><h3 class="font-bold mb-4">Conversation & progress</h3>
      <?= $acts ? ui_timeline(array_map(fn($a) => [match ($a['activity_type']) { 'call' => 'fa-phone', 'sms' => 'fa-comment-sms', 'email' => 'fa-envelope', 'whatsapp' => 'fa-comment', 'meeting' => 'fa-people-group', 'site_visit' => 'fa-person-walking', 'offer' => 'fa-handshake', 'stage_change' => 'fa-arrow-right-arrow-left', default => 'fa-note-sticky' },
        $a['done_at'] ? ($a['outcome'] === 'positive' ? 'green' : ($a['outcome'] === 'negative' ? 'red' : 'blue')) : 'amber',
        ($a['done_at'] ? '' : '<span class="text-amber-600">Scheduled · </span>') . e($a['summary']), ($a['details'] ? e($a['details']) : '') . (!$a['done_at'] ? ' <button class="text-xs font-bold text-accent" data-action="' . e(url('api.php', ['r' => 'sales/done-activity', 'id' => $a['id']])) . '" data-prompt="Outcome (positive / neutral / negative)" data-prompt-name="outcome" data-prompt-optional="1">Mark done</button>' : ''),
        fdatetime($a['done_at'] ?? $a['due_at']), $a['who']], $acts)) : ui_empty('fa-comments', 'No activity yet') ?></div>
  </div>
  <aside class="space-y-4">
    <div class="vk-panel p-5"><dl class="space-y-2 text-sm">
      <?php foreach ([['Phone', $l['phone']], ['Email', $l['email']], ['Source', str_replace('_', ' ', $l['source'])], ['Interest', ucfirst(str_replace('_', ' ', $l['interest']))], ['Property', $l['property']], ['Unit', $l['unit_no']], ['Budget', ($l['budget_min'] ? money($l['budget_min'], true, true) : '') . ($l['budget_max'] ? ' – ' . money($l['budget_max'], true, true) : '')], ['Financing', $l['financing']], ['Agent', $l['agent']], ['Created', fdate($l['created_at'])], ['vikao.io order', $l['ecom_order_number']]] as [$k, $v]) if ($v): ?>
        <div class="flex justify-between gap-2"><dt class="text-muted"><?= $k ?></dt><dd class="font-semibold text-right"><?= e($v) ?></dd></div><?php endif; ?></dl>
      <?php if ($l['notes']): ?><p class="mt-3 text-sm border-t border-line pt-3"><?= nl2br(e($l['notes'])) ?></p><?php endif; ?>
      <?php if ($l['lost_reason']): ?><p class="mt-3 text-sm text-rose-600">Lost: <?= e($l['lost_reason']) ?></p><?php endif; ?></div>
    <?php if ($open): ?><div class="vk-panel p-5"><h3 class="font-bold mb-2 text-sm">Upcoming follow-ups</h3><?php foreach ($open as $a): ?><p class="text-sm <?= $a['due_at'] < date('Y-m-d H:i:s') ? 'text-rose-600 font-semibold' : '' ?>"><i class="fa-regular fa-bell"></i> <?= fdatetime($a['due_at']) ?> · <?= e($a['summary']) ?></p><?php endforeach; ?></div><?php endif; ?>
  </aside>
</div>
<?= lead_modal_html() ?>
<?php if (can('sales.deals.manage')) { $def = $l['unit_id'] ? default_sales_commission((int)$l['unit_id']) : ['type' => 'percentage', 'value' => (float)setting('default_sales_commission_pct', 3)];
echo ui_modal('m-deal', 'Create deal', '<input type="hidden" name="lead_id" value="' . $id . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Unit *', ui_unit_picker('unit_id', $l['unit_id'] ? (int)$l['unit_id'] : null)) . ui_field('Agreed price *', ui_input('agreed_price', $l['sale_price'] ?? '', 'number', 'step="any" required'))
  . ui_field('Deposit', ui_input('deposit_amount', '', 'number')) . ui_field('Payment plan', ui_select('payment_plan', ui_kv_options(['cash' => 'Cash', 'installments' => 'Instalments', 'mortgage' => 'Mortgage']))) . ui_field('Instalments (count)', ui_input('installments', '', 'number'))
  . ui_field('Expected close', ui_input('expected_close_date', date('Y-m-d', strtotime('+30 days')), 'date')) . ui_field('Commission type', ui_select('commission_type', ui_kv_options(['percentage' => 'Percentage of price', 'fixed' => 'Fixed amount'], $def['type']))) . ui_field('Commission value', ui_input('commission_value', $def['value'], 'number', 'step="any"'))
  . '</div><p class="text-xs text-muted">Commission defaults come from the owner\'s management agreement. The unit is marked <b>reserved</b> while the deal is open.</p>', url('api.php', ['r' => 'sales/create-deal']), 'Create deal', 'max-w-2xl', 'fa-handshake'); }
