<?php
$title = 'Consume payment';
$subtitle = 'Split a payment across bills — exact, partial or full arrears — and keep any overpayment for the next cycle';
$uuid = (string)($_GET['payment'] ?? '');
$pay = $uuid ? one("SELECT pm.*, pt.display_name, pt.phone AS party_phone, u.unit_no, fa.name AS account, fa.account_type FROM fin_payments pm LEFT JOIN prpt_parties pt ON pt.id = pm.party_id LEFT JOIN prpt_units u ON u.id = pm.unit_id LEFT JOIN fin_accounts fa ON fa.id = pm.account_id WHERE pm.uuid = ?", [$uuid]) : null;
$crumbs = [['Payments', page('finance/payments')]];
if (!$pay):
    $queue = all("SELECT pm.*, pt.display_name, u.unit_no FROM fin_payments pm LEFT JOIN prpt_parties pt ON pt.id = pm.party_id LEFT JOIN prpt_units u ON u.id = pm.unit_id WHERE pm.status IN ('unallocated','partially_allocated') ORDER BY pm.party_id IS NULL DESC, pm.paid_at LIMIT 100"); ?>
  <div class="max-w-3xl">
    <div class="vk-panel p-5 mb-5"><span class="vk-label">Find a payment</span><select data-pay-search class="vk-select" id="pay-find"><option value="">Receipt no., M-Pesa code, name or phone…</option></select></div>
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3.5 border-b border-line font-bold">Waiting to be consumed <span class="text-muted font-normal">(<?= count($queue) ?>)</span></div>
      <table class="vk-table"><tbody>
      <?php foreach ($queue as $q): ?><tr data-href="<?= e(page('finance/consume', ['payment' => $q['uuid']])) ?>"><td><b><?= e($q['receipt_no']) ?></b><span class="block text-xs text-muted"><?= fdatetime($q['paid_at']) ?> · <?= e($q['reference'] ?: $q['method']) ?></span></td>
        <td class="text-sm"><?= $q['party_id'] ? e($q['display_name']) . ($q['unit_no'] ? ' · ' . e($q['unit_no']) : '') : '<span class="text-rose-600 font-semibold">Unknown payer</span> <span class="text-xs text-muted">' . e($q['payer_name'] . ' ' . $q['payer_phone']) . '</span>' ?></td>
        <td class="num"><span class="text-xs text-muted">of <?= money($q['amount'], false) ?></span><b class="block text-accent"><?= money($q['unallocated_amount'], false) ?></b></td><td class="w-10"><i class="fa-solid fa-chevron-right text-muted"></i></td></tr><?php endforeach; ?>
      </tbody></table><?= $queue ? '' : ui_empty('fa-circle-check', 'All payments are consumed') ?></div>
  </div>
  <?php page_script('<script>$(function(){const $s=$("#pay-find");$s.attr("data-party-search",null);(function(){const $w=$("<div class=\"relative\"></div>");$s.after($w).addClass("hidden").appendTo($w);const $i=$("<input class=\"vk-input\" autocomplete=\"off\" placeholder=\"Receipt no., M-Pesa code, name or phone…\">").prependTo($w);const $r=$("<div class=\"select-search-results hidden\"></div>").appendTo($w);let t;$i.on("input focus",function(){clearTimeout(t);const q=this.value;t=setTimeout(()=>$.get(VK.url("api.php",{r:"finance/search-payments",q})).then(x=>{$r.html((x.items||[]).map(i=>`<button type=button data-id="${i.id}"><b>${VK.esc(i.label)}</b><span class="block text-xs text-muted">${VK.esc(i.sub)}</span></button>`).join("")||"<p class=\"p-3 text-sm text-muted\">No open payments match</p>").removeClass("hidden")}),200)});$r.on("mousedown","button",function(){location.href=VK.url("index.php",{r:"finance/consume",payment:$(this).data("id")})});$i.on("blur",()=>setTimeout(()=>$r.addClass("hidden"),150))})()})</script>');
  return;
endif;

$title = 'Consume ' . $pay['receipt_no'];
$allocs = all("SELECT a.*, i.invoice_no, l.description, bt.name AS bt, u.unit_no FROM fin_allocations a JOIN bln_invoices i ON i.id = a.invoice_id JOIN bln_invoice_lines l ON l.id = a.invoice_line_id JOIN cfg_bill_types bt ON bt.id = a.bill_type_id LEFT JOIN prpt_units u ON u.id = i.unit_id WHERE a.payment_id = ? AND a.reversed_at IS NULL ORDER BY a.id", [$pay['id']]);
$credits = all("SELECT c.*, u.unit_no, bt.name AS bt FROM fin_credits c LEFT JOIN prpt_units u ON u.id = c.unit_id LEFT JOIN cfg_bill_types bt ON bt.id = c.bill_type_id WHERE c.payment_id = ? AND c.status <> 'cancelled'", [$pay['id']]);
$avail = (float)$pay['unallocated_amount']; $isOpen = $avail > 0.004 && $pay['status'] !== 'reversed';
$pct = (float)$pay['amount'] > 0 ? ((float)$pay['allocated_amount'] + (float)$pay['credit_amount']) / (float)$pay['amount'] * 100 : 0;
$a = [];
if (can('finance.payments.view') && $pay['party_id']) $a[] = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'finance/send-receipt', 'id' => $pay['id']])) . '" data-no-reload><i class="fa-solid fa-paper-plane"></i>Send receipt</button>';
if (can('finance.allocate') && ($allocs || $credits) && $pay['status'] !== 'reversed') $a[] = '<button class="vk-btn-ghost" data-action="' . e(url('api.php', ['r' => 'finance/unallocate', 'id' => $pay['id']])) . '" data-confirm="Undo every allocation of this payment so you can consume it again?"><i class="fa-solid fa-rotate-left"></i>Undo allocations</button>';
if (can('finance.payments.reverse') && $pay['status'] !== 'reversed') $a[] = '<button class="vk-btn-ghost !text-rose-600" data-action="' . e(url('api.php', ['r' => 'finance/reverse-payment', 'id' => $pay['id']])) . '" data-prompt="Why reverse this payment? (bounced cheque, wrong account…)"><i class="fa-solid fa-ban"></i>Reverse</button>';
if (can('finance.payments.reverse') && !$allocs && !$credits && $pay['source'] !== 'gateway') $a[] = '<button class="vk-btn-ghost !text-rose-600" data-action="' . e(url('api.php', ['r' => 'finance/delete-payment', 'id' => $pay['id']])) . '" data-confirm="Delete this payment record permanently?"><i class="fa-regular fa-trash-can"></i></button>';
$actions = implode('', $a);
?>
<div class="grid grid-cols-1 xl:grid-cols-[300px_1fr] gap-6 items-start">
  <!-- payment card -->
  <aside class="space-y-4 xl:sticky xl:top-20">
    <div class="vk-panel overflow-hidden">
      <div class="p-5 bg-gradient-to-br from-slate-800 to-slate-900 text-white">
        <div class="flex items-center gap-2 text-xs text-white/70"><i class="fa-solid <?= $pay['method'] === 'mpesa' ? 'fa-mobile-screen' : ($pay['method'] === 'card' ? 'fa-credit-card' : ($pay['method'] === 'cash' ? 'fa-money-bills' : 'fa-building-columns')) ?>"></i><?= e(strtoupper(str_replace('_', ' ', $pay['method']))) ?> · <?= e($pay['account']) ?></div>
        <p class="mt-2 text-3xl font-extrabold tracking-tight"><?= money($pay['amount']) ?></p>
        <p class="font-mono text-sm text-white/80"><?= e($pay['reference'] ?: $pay['receipt_no']) ?></p>
        <div class="mt-4 h-2 rounded-full bg-white/15 overflow-hidden"><div class="h-full bg-amber-400" style="width:<?= min(100, $pct) ?>%"></div></div>
        <div class="mt-1.5 flex justify-between text-xs text-white/70"><span><?= money($pay['allocated_amount'] + $pay['credit_amount'], false) ?> consumed</span><span><b class="text-amber-300"><?= money($avail, false) ?></b> left</span></div>
      </div>
      <dl class="p-5 space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-muted">Receipt</dt><dd class="font-semibold"><?= e($pay['receipt_no']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-muted">Paid</dt><dd class="font-semibold"><?= fdatetime($pay['paid_at']) ?></dd></div>
        <div class="flex justify-between"><dt class="text-muted">From</dt><dd class="font-semibold text-right"><?= e($pay['payer_name'] ?: '—') ?><span class="block text-xs text-muted"><?= e($pay['payer_phone']) ?></span></dd></div>
        <div class="flex justify-between"><dt class="text-muted">Status</dt><dd><?= pill($pay['status']) ?></dd></div>
        <?php if ($pay['notes']): ?><p class="text-xs text-muted pt-1"><?= e($pay['notes']) ?></p><?php endif; ?>
      </dl>
      <div class="px-5 pb-5"><span class="vk-label">Payer (whose bills)</span><?= ui_party_picker('payer', $pay['party_id'] ? (int)$pay['party_id'] : null, '', 'id="payer"') ?>
        <?php if (!$pay['party_id']): ?><p class="text-xs text-rose-600 mt-1"><i class="fa-solid fa-circle-question"></i> We couldn't match this payment automatically. Pick the payer — the bill reference was “<?= e(str_replace('Bill ref: ', '', (string)$pay['notes'])) ?>”.</p><?php endif; ?></div>
    </div>
    <?php if ($allocs || $credits): ?>
    <div class="vk-panel overflow-hidden"><div class="px-5 py-3 border-b border-line font-bold text-sm">Already consumed</div>
      <ul class="divide-y divide-line text-sm">
        <?php foreach ($allocs as $x): ?><li class="px-5 py-2.5 flex gap-2"><span class="flex-1 min-w-0"><a class="font-semibold hover:text-accent" href="<?= e(page('billing/invoice', ['id' => $x['invoice_id']])) ?>"><?= e($x['invoice_no']) ?></a> · <?= e($x['bt']) ?><span class="block text-xs text-muted truncate"><?= e(($x['unit_no'] ? $x['unit_no'] . ' · ' : '') . $x['description']) ?></span></span><b class="tabular-nums"><?= money($x['amount'], false) ?></b></li><?php endforeach; ?>
        <?php foreach ($credits as $c): ?><li class="px-5 py-2.5 flex gap-2 bg-violet-500/5"><span class="flex-1"><i class="fa-solid fa-piggy-bank text-violet-500"></i> Overpayment<span class="block text-xs text-muted"><?= e(trim(($c['unit_no'] ? 'Unit ' . $c['unit_no'] : 'Any unit') . ' · ' . ($c['bt'] ?: 'any bill'))) ?> · <?= money($c['remaining'], false) ?> left</span></span>
          <b class="tabular-nums"><?= money($c['amount'], false) ?></b><?php if (can('finance.allocate') && $c['status'] === 'open' && (float)$c['remaining'] === (float)$c['amount']): ?><button class="text-muted hover:text-rose-600" title="Release back" data-action="<?= e(url('api.php', ['r' => 'finance/cancel-credit', 'id' => $c['id']])) ?>" data-confirm="Release this overpayment back to the payment?"><i class="fa-solid fa-xmark"></i></button><?php endif; ?></li><?php endforeach; ?>
      </ul></div>
    <?php endif; ?>
  </aside>

  <!-- consume workspace -->
  <div class="min-w-0">
    <?php if (!$isOpen): ?>
      <div class="vk-panel p-6 flex items-center gap-4"><span class="grid h-12 w-12 place-items-center rounded-2xl <?= $pay['status'] === 'reversed' ? 'bg-rose-500/10 text-rose-600' : 'bg-emerald-500/10 text-emerald-600' ?> text-xl"><i class="fa-solid <?= $pay['status'] === 'reversed' ? 'fa-ban' : 'fa-circle-check' ?>"></i></span>
        <div><p class="font-bold"><?= $pay['status'] === 'reversed' ? 'This payment was reversed' : 'Fully consumed' ?></p><p class="text-sm text-muted"><?= $pay['status'] === 'reversed' ? e($pay['reversed_reason']) : 'Every shilling is allocated to bills or held as overpayment. Use “Undo allocations” to redo it.' ?></p></div>
        <a href="<?= e(page('finance/consume')) ?>" class="ml-auto vk-btn-ghost">Next payment</a></div>
    <?php else: ?>
    <form id="consume" class="space-y-4">
      <input type="hidden" name="payment_id" value="<?= $pay['id'] ?>"><input type="hidden" name="party_id" id="f-party" value="<?= (int)$pay['party_id'] ?: '' ?>">
      <div class="vk-panel p-4 flex flex-wrap items-center gap-2">
        <span class="text-sm font-bold mr-1">Quick fill</span>
        <button type="button" class="vk-chip" data-fill="oldest"><i class="fa-solid fa-clock-rotate-left"></i>Oldest bills first</button>
        <button type="button" class="vk-chip" data-fill="exact"><i class="fa-solid fa-equals"></i>Exact on ticked</button>
        <button type="button" class="vk-chip" data-fill="clear"><i class="fa-solid fa-eraser"></i>Clear</button>
        <span class="mx-1 h-6 w-px bg-line"></span>
        <select id="flt-unit" class="vk-select !w-auto !py-1.5 text-sm"><option value="">All units</option></select>
        <select id="flt-bt" class="vk-select !w-auto !py-1.5 text-sm"><option value="">All bills</option></select>
        <button type="button" class="vk-chip" data-fill="full-type" title="Select every open line of the chosen bill type / unit and pay it in full"><i class="fa-solid fa-layer-group"></i>Full arrears of filter</button>
      </div>
      <div class="vk-panel overflow-hidden">
        <div class="overflow-x-auto"><table class="vk-table" id="lines">
          <thead><tr><th class="w-8"><input type="checkbox" id="chk-all" class="accent-amber-600"></th><th>Bill</th><th>Invoice · due</th><th class="num">Paid / billed</th><th class="num">Balance</th><th class="num">Pay now</th></tr></thead>
          <tbody><tr><td colspan="6" class="py-10 text-center text-muted"><?= $pay['party_id'] ? '<i class="fa-solid fa-circle-notch fa-spin"></i> Loading bills…' : 'Choose the payer on the left to see their bills.' ?></td></tr></tbody>
        </table></div>
      </div>
      <!-- overpayment -->
      <div class="vk-panel p-5 hidden border-violet-500/40" id="over-box">
        <div class="flex items-start gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-violet-500/10 text-violet-600"><i class="fa-solid fa-piggy-bank"></i></span>
          <div class="flex-1"><p class="font-bold">Overpayment of <span id="over-amt" class="text-violet-600"></span></p><p class="text-sm text-muted">Keep it as credit — it is consumed automatically when the next matching invoice is raised.</p>
            <label class="vk-switch mt-2"><input type="checkbox" id="over-on" checked>Hold the remainder as overpayment</label>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3" id="over-fields">
              <label class="block"><span class="vk-label">For unit / account</span><select name="credit_unit_id" id="over-unit" class="vk-select"><option value="">Any of their units</option></select></label>
              <label class="block"><span class="vk-label">For bill</span><select name="credit_bill_type_id" id="over-bt" class="vk-select"><option value="">Any bill</option></select></label>
              <label class="block"><span class="vk-label">Note</span><input name="credit_notes" class="vk-input" placeholder="e.g. Advance rent for next month"></label>
            </div></div></div>
        <input type="hidden" name="credit_amount" id="credit-amount" value="0"><input type="hidden" name="credit_lease_id" id="credit-lease">
      </div>
      <!-- sticky summary bar -->
      <div class="sticky bottom-3 z-10 vk-panel p-4 shadow-xl flex flex-wrap items-center gap-x-6 gap-y-2 border-accent/40">
        <div><span class="text-xs text-muted block">Available</span><b class="text-lg tabular-nums"><?= money($avail) ?></b></div>
        <div><span class="text-xs text-muted block">To bills</span><b class="text-lg tabular-nums" id="sum-bills">KSh 0.00</b></div>
        <div><span class="text-xs text-muted block">Remaining</span><b class="text-lg tabular-nums" id="sum-left"><?= money($avail) ?></b></div>
        <div class="text-xs font-semibold" id="sum-state"></div>
        <label class="vk-switch text-sm ml-auto"><input type="checkbox" name="send_receipt" value="1" checked>Send receipt</label>
        <button class="vk-btn-accent" id="btn-consume" disabled><i class="fa-solid fa-check-double"></i>Consume</button>
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php if ($isOpen): ob_start(); ?>
<script>
(function () {
  const AVAIL = <?= json_encode(round($avail, 2)) ?>, PAY_UNIT = <?= json_encode($pay['unit_id'] ? (int)$pay['unit_id'] : null) ?>;
  const $tb = $('#lines tbody'); let LINES = [], ACCOUNTS = [];
  const fmt = n => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  function load(party) {
    $('#f-party').val(party || '');
    if (!party) { $tb.html('<tr><td colspan="6" class="py-10 text-center text-muted">Choose the payer on the left to see their bills.</td></tr>'); return; }
    VK.api('finance/open-lines', { party_id: party }).then(r => {
      LINES = r.lines; ACCOUNTS = r.accounts;
      const units = [...new Map(LINES.filter(l => l.unit_id).map(l => [l.unit_id, l.unit_no])).entries()], bts = [...new Map(LINES.map(l => [l.bill_type_id, l.bill_type])).entries()];
      $('#flt-unit').html('<option value="">All units</option>' + units.map(([id, n]) => `<option value="${id}">${VK.esc(n)}</option>`).join(''));
      $('#flt-bt').html('<option value="">All bills</option>' + bts.map(([id, n]) => `<option value="${id}">${VK.esc(n)}</option>`).join(''));
      $('#over-unit').html('<option value="">Any of their units</option>' + ACCOUNTS.map(a => `<option value="${a.unit_id}" data-lease="${a.lease_id}" ${+a.unit_id === +PAY_UNIT ? 'selected' : ''}>${VK.esc(a.unit_no)} · ${VK.esc(a.lease_no)}</option>`).join(''));
      $('#over-bt').html('<option value="">Any bill</option>' + r.bill_types.map(b => `<option value="${b.id}">${VK.esc(b.name)}</option>`).join(''));
      render();
    });
  }
  function render() {
    if (!LINES.length) { $tb.html('<tr><td colspan="6" class="py-12 text-center"><i class="fa-solid fa-face-smile text-3xl text-emerald-500"></i><p class="mt-2 font-semibold">No open bills</p><p class="text-sm text-muted">The whole amount can be kept as an overpayment / prepayment.</p></td></tr>'); calc(); return; }
    let html = '', grp = null;
    LINES.forEach(l => {
      const g = (l.unit_no || 'No unit') + ' · ' + (l.property || '');
      if (g !== grp) { grp = g; html += `<tr class="bg-surface2/60" data-group><td colspan="6" class="!py-1.5 text-xs font-bold uppercase tracking-wide text-muted"><i class="fa-solid fa-door-open mr-1"></i>${VK.esc(g)}</td></tr>`; }
      html += `<tr data-line="${l.id}" data-unit="${l.unit_id || ''}" data-bt="${l.bill_type_id}">
        <td><input type="checkbox" class="chk accent-amber-600"></td>
        <td><span class="inline-block h-2.5 w-2.5 rounded-full mr-1.5" style="background:${l.color}"></span><b>${VK.esc(l.bill_type)}</b><span class="block text-[11px] text-muted truncate max-w-[180px]">${VK.esc(l.description)}</span></td>
        <td class="text-sm whitespace-nowrap"><a href="${l.invoice_url}" target="_blank" class="hover:text-accent">${VK.esc(l.invoice_no)}</a><span class="block text-[11px] ${l.overdue ? 'text-rose-600 font-semibold' : 'text-muted'}">${l.overdue ? 'overdue · ' : 'due '}${VK.esc(l.due_label)}</span></td>
        <td class="num text-sm whitespace-nowrap">${l.paid > 0 ? `<a href="#" class="text-emerald-600 underline decoration-dotted" data-paid='{"line_id":${l.id}}'>${fmt(l.paid)}</a>` : '<span class="text-muted">0.00</span>'}<span class="block text-[11px] text-muted">of ${fmt(l.total)}</span></td>
        <td class="num font-bold whitespace-nowrap">${fmt(l.balance)}</td>
        <td><div class="flex items-center gap-1"><input name="lines[${l.id}]" class="vk-input !py-1.5 !px-2 !w-24 text-right font-semibold tabular-nums amt" inputmode="decimal" placeholder="0.00"><button type="button" class="vk-icon-btn !h-8 !w-8 shrink-0 full" title="Pay in full"><i class="fa-solid fa-check text-xs"></i></button></div></td></tr>`;
    });
    $tb.html(html); applyFilter(); calc();
  }
  function applyFilter() { const u = $('#flt-unit').val(), b = $('#flt-bt').val(); $tb.find('tr[data-line]').each(function () { $(this).toggle((!u || String($(this).data('unit')) === u) && (!b || String($(this).data('bt')) === b)); }); }
  const line = $tr => LINES.find(l => l.id === +$tr.data('line'));
  function calc() {
    let bills = 0; $tb.find('.amt').each(function () { const v = VK.num(this.value), l = line($(this).closest('tr')); bills += v; $(this).toggleClass('is-invalid', v > l.balance + 0.004); $(this).closest('tr').toggleClass('bg-accent/5', v > 0).find('.chk').prop('checked', v > 0 || $(this).closest('tr').find('.chk').prop('checked')); });
    bills = Math.round(bills * 100) / 100; const left = Math.round((AVAIL - bills) * 100) / 100, overOn = $('#over-on').prop('checked');
    $('#sum-bills').text(VK.money(bills)); $('#sum-left').text(VK.money(left)).toggleClass('text-rose-600', left < 0).toggleClass('text-violet-600', left > 0);
    $('#over-box').toggleClass('hidden', !(left > 0.004)); $('#over-amt').text(VK.money(left)); $('#over-fields').toggle(overOn);
    $('#credit-amount').val(left > 0 && overOn ? left.toFixed(2) : 0); $('#credit-lease').val($('#over-unit option:selected').data('lease') || '');
    const bad = $tb.find('.amt.is-invalid').length, consumed = bills + (left > 0 && overOn ? left : 0);
    let st = ''; if (left < -0.004) st = '<span class="text-rose-600"><i class="fa-solid fa-triangle-exclamation"></i> More than the payment</span>'; else if (bad) st = '<span class="text-rose-600"><i class="fa-solid fa-triangle-exclamation"></i> A line exceeds its balance</span>';
    else if (left > 0.004 && !overOn) st = '<span class="text-amber-600">Underpaid bills · ' + VK.money(left) + ' stays unallocated</span>'; else if (left > 0.004) st = '<span class="text-violet-600">Overpayment → credit</span>'; else if (bills > 0) st = '<span class="text-emerald-600"><i class="fa-solid fa-circle-check"></i> Exact</span>';
    $('#sum-state').html(st); $('#btn-consume').prop('disabled', !!(left < -0.004 || bad || consumed <= 0));
  }
  function fillOldest() { let left = AVAIL; $tb.find('tr[data-line]:visible').each(function () { const l = line($(this)), a = Math.min(left, l.balance); $(this).find('.amt').val(a > 0 ? a.toFixed(2) : ''); left = Math.round((left - Math.max(a, 0)) * 100) / 100; }); calc(); }
  $('[data-fill]').on('click', function () {
    const m = $(this).data('fill');
    if (m === 'clear') { $tb.find('.amt').val(''); $tb.find('.chk').prop('checked', false); }
    else if (m === 'oldest') fillOldest();
    else if (m === 'exact') { const $t = $tb.find('tr[data-line]').filter((i, x) => $(x).find('.chk').prop('checked')); if (!$t.length) return VK.toast('Tick the bills to pay in full', 'info'); $t.each(function () { $(this).find('.amt').val(line($(this)).balance.toFixed(2)); }); }
    else if (m === 'full-type') { if (!$('#flt-bt').val() && !$('#flt-unit').val()) return VK.toast('Choose a unit and/or bill type filter first', 'info'); $tb.find('.amt').val(''); $tb.find('tr[data-line]:visible').each(function () { $(this).find('.amt').val(line($(this)).balance.toFixed(2)); }); }
    calc();
  });
  $tb.on('input', '.amt', calc).on('click', '.full', function () { const $tr = $(this).closest('tr'); $tr.find('.amt').val(line($tr).balance.toFixed(2)); calc(); })
     .on('change', '.chk', function () { const $tr = $(this).closest('tr'); if (!this.checked) $tr.find('.amt').val(''); calc(); });
  $('#chk-all').on('change', function () { $tb.find('tr[data-line]:visible .chk').prop('checked', this.checked).trigger('change'); });
  $('#flt-unit, #flt-bt').on('change', applyFilter);
  $('#over-on, #over-unit').on('change', calc);
  $('#payer').on('change', function () { load(this.value); });
  $('#consume').on('submit', async function (e) {
    e.preventDefault(); const left = VK.num($('#sum-left').text().replace('KSh', ''));
    const ok = await VK.confirm({ title: 'Consume this payment?', html: `<div class="text-sm">To bills: <b>${$('#sum-bills').text()}</b>${VK.num($('#credit-amount').val()) > 0 ? '<br>Overpayment held: <b>' + VK.money($('#credit-amount').val()) + '</b>' : ''}</div>` });
    if (!ok) return; const $b = $('#btn-consume'); VK.btnLoading($b, true, 'Consuming');
    VK.api('finance/allocate', $(this).serialize(), { silent: true }).then(r => { VK.toast(r.message); setTimeout(() => location.reload(), 600); }).catch(j => { VK.btnLoading($b, false); VK.toast((j && (j.errors && Object.values(j.errors)[0])) || (j && j.message) || 'Failed', 'error'); });
  });
  load($('#payer').val());
})();
</script>
<?php page_script(ob_get_clean()); else: page_script('<script>$("#payer").on("change",function(){if(this.value)VK.api("finance/assign-payer",{id:' . (int)$pay['id'] . ',party_id:this.value}).then(r=>{VK.toast(r.message);setTimeout(()=>location.reload(),400)})})</script>'); endif;
if (!$pay['party_id'] && $isOpen) page_script('<script>$("#payer").on("change",function(){if(this.value)VK.api("finance/assign-payer",{id:' . (int)$pay['id'] . ',party_id:this.value},{silent:true})})</script>');
