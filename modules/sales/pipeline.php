<?php
require_once __DIR__ . '/_partials.php';
$title = 'Sales pipeline';
$subtitle = 'Drag leads between stages — every move is logged on the lead';
$mine = !empty($_GET['mine']); $interest = (string)($_GET['interest'] ?? '');
$stages = all("SELECT * FROM sls_stages ORDER BY sort_order");
$w = ['1=1']; $p = [];
if ($mine) { $w[] = 'l.assigned_to = :me'; $p['me'] = staff_id(); }
if ($interest) { $w[] = 'l.interest = :i'; $p['i'] = $interest; }
$leads = all("SELECT l.*, u.unit_no, pr.name AS property, CONCAT(su.first_name, ' ', COALESCE(su.last_name,'')) AS agent,
    (SELECT MAX(agreed_price) FROM sls_deals d WHERE d.lead_id = l.id AND d.status <> 'cancelled') AS deal_value
    FROM sls_leads l LEFT JOIN prpt_units u ON u.id = l.unit_id LEFT JOIN prpt_properties pr ON pr.id = l.property_id LEFT JOIN sys_users su ON su.id = l.assigned_to
    WHERE " . implode(' AND ', $w) . " AND (l.updated_at > DATE_SUB(NOW(), INTERVAL 120 DAY) OR l.stage_id NOT IN (SELECT id FROM sls_stages WHERE is_won = 1 OR is_lost = 1)) ORDER BY l.next_follow_up_at IS NULL, l.next_follow_up_at", $p);
$by = []; foreach ($leads as $l) $by[$l['stage_id']][] = $l;
$weighted = 0; foreach ($stages as $s) foreach ($by[$s['id']] ?? [] as $l) $weighted += (float)($l['deal_value'] ?: $l['budget_max'] ?: 0) * $s['probability'] / 100;
$actions = '<button class="vk-btn-accent" data-crud-new="#m-lead" data-title="New lead"><i class="fa-solid fa-user-plus"></i>New lead</button>';
?>
<div class="flex flex-wrap items-center gap-2 mb-4">
  <a href="<?= e(page('sales/pipeline', array_filter(['interest' => $interest, 'mine' => $mine ? null : 1]))) ?>" class="vk-chip <?= $mine ? '!border-accent !bg-accent/10' : '' ?>"><i class="fa-solid fa-user"></i>My leads</a>
  <?php foreach (['' => 'All', 'buy' => 'Buying', 'rent' => 'Renting', 'invest' => 'Investing', 'short_stay' => 'Short stay'] as $k => $l): ?><a href="<?= e(page('sales/pipeline', array_filter(['interest' => $k, 'mine' => $mine ?: null]))) ?>" class="vk-chip <?= $interest === $k ? '!border-accent !bg-accent/10' : '' ?>"><?= $l ?></a><?php endforeach; ?>
  <span class="ml-auto text-sm text-muted">Weighted pipeline <b class="text-ink"><?= money($weighted, true, true) ?></b></span>
</div>
<div class="flex gap-4 overflow-x-auto pb-4 -mx-1 px-1" id="board">
  <?php foreach ($stages as $s): $list = $by[$s['id']] ?? []; ?>
  <div class="w-72 shrink-0 flex flex-col">
    <div class="flex items-center gap-2 mb-2 px-1"><span class="h-2.5 w-2.5 rounded-full" style="background:<?= e($s['color_hex'] ?: '#94A3B8') ?>"></span><b class="text-sm"><?= e($s['name']) ?></b><span class="text-xs text-muted"><?= count($list) ?> · <?= (int)$s['probability'] ?>%</span></div>
    <div class="col flex-1 min-h-[300px] space-y-2 rounded-2xl bg-surface2/50 p-2" data-stage="<?= $s['id'] ?>" data-lost="<?= (int)$s['is_lost'] ?>">
      <?php foreach ($list as $l): $due = $l['next_follow_up_at'] && $l['next_follow_up_at'] < date('Y-m-d H:i:s'); ?>
      <a href="<?= e(page('sales/lead', ['id' => $l['id']])) ?>" draggable="true" data-id="<?= $l['id'] ?>" class="card block vk-panel p-3 hover:border-accent/60 cursor-grab active:cursor-grabbing">
        <div class="flex items-start gap-2"><b class="text-sm flex-1"><?= e($l['full_name']) ?></b><?= $l['score'] ? '<span class="text-[11px] text-amber-500">' . str_repeat('★', (int)$l['score']) . '</span>' : '' ?></div>
        <p class="text-xs text-muted mt-0.5"><?= e(ucfirst(str_replace('_', ' ', $l['interest']))) ?><?= $l['unit_no'] ? ' · ' . e($l['unit_no']) : ($l['property'] ? ' · ' . e($l['property']) : '') ?></p>
        <?php if ($l['deal_value'] || $l['budget_max']): ?><p class="text-xs font-semibold mt-1"><?= $l['deal_value'] ? money($l['deal_value'], true, true) . ' deal' : 'Budget ≤ ' . money($l['budget_max'], true, true) ?></p><?php endif; ?>
        <div class="flex items-center gap-2 mt-2 text-[11px]"><?= ui_avatar($l['agent'] ?: '?', 'h-5 w-5 !text-[8px]') ?><span class="text-muted truncate flex-1"><?= e($l['agent']) ?></span>
          <?php if ($l['next_follow_up_at']): ?><span class="<?= $due ? 'text-rose-600 font-bold' : 'text-muted' ?>"><i class="fa-regular fa-bell"></i> <?= fdate($l['next_follow_up_at'], 'j M') ?></span><?php endif; ?></div>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?= lead_modal_html() ?>
<?php ob_start(); ?>
<script>
(function () {
  let drag = null;
  $('#board').on('dragstart', '.card', function (e) { drag = this; e.originalEvent.dataTransfer.effectAllowed = 'move'; $(this).addClass('opacity-50'); })
    .on('dragend', '.card', function () { $(this).removeClass('opacity-50'); $('.col').removeClass('ring-2 ring-accent'); })
    .on('dragover', '.col', function (e) { e.preventDefault(); $(this).addClass('ring-2 ring-accent'); })
    .on('dragleave', '.col', function () { $(this).removeClass('ring-2 ring-accent'); })
    .on('drop', '.col', async function (e) {
      e.preventDefault(); if (!drag) return; const $col = $(this), id = $(drag).data('id'); let reason = '';
      if ($col.data('lost')) { const r = await Swal.fire({ title: 'Why was this lead lost?', input: 'text', showCancelButton: true }); if (!r.isConfirmed) return; reason = r.value; }
      $col.append(drag); VK.api('sales/lead-stage', { id, stage_id: $col.data('stage'), reason }).then(r => VK.toast(r.message)); drag = null;
    });
})();
</script>
<?php page_script(ob_get_clean());
