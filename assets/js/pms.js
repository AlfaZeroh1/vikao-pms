/* =====================================================================
   Vikao PMS — global behaviours (jQuery)
   ===================================================================== */
window.VK = (function ($) {
  'use strict';
  const base = $('meta[name="base-url"]').attr('content') || '';
  const csrf = $('meta[name="csrf-token"]').attr('content');
  const url = (p, q) => base + '/' + String(p || '').replace(/^\//, '') + (q ? '?' + $.param(q) : '');
  const api = (r, data, opts = {}) => {
    const isForm = data instanceof FormData;
    return $.ajax(Object.assign({ url: url('api.php', { r }), method: 'POST', data, dataType: 'json', processData: !isForm, contentType: isForm ? false : 'application/x-www-form-urlencoded; charset=UTF-8' }, opts))
      .catch(xhr => {
        const j = xhr.responseJSON || {};
        if (xhr.status === 401) { location.href = url('login.php'); }
        else if (!opts.silent) toast(j.message || 'Something went wrong.', 'error');
        return $.Deferred().reject(j, xhr).promise();
      });
  };
  $.ajaxSetup({ headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' } });

  const Toast = Swal.mixin({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
  const toast = (m, icon = 'success') => Toast.fire({ icon, title: m });
  const confirm = (o) => Swal.fire(Object.assign({ icon: 'question', showCancelButton: true, confirmButtonText: 'Yes, continue', reverseButtons: true, focusCancel: true }, o)).then(r => r.isConfirmed ? r : null);
  const money = (n) => 'KSh ' + Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/,/g, '')); return isNaN(n) ? 0 : n; };
  const esc = (s) => $('<div>').text(s ?? '').html();

  function showErrors($form, errors) {
    $form.find('.vk-error').remove(); $form.find('.is-invalid').removeClass('is-invalid');
    let first = null;
    $.each(errors || {}, (f, msg) => {
      let $f = $form.find('[name="' + f + '"], [name="' + f + '[]"], [data-field="' + f + '"]').first();
      if (!$f.length) { if (!first) toast(msg, 'error'); return; }
      const $w = $f.closest('[data-field-wrap]').length ? $f.closest('[data-field-wrap]') : $f.parent();
      $f.addClass('is-invalid'); $w.append('<p class="vk-error"><i class="fa-solid fa-circle-exclamation"></i> ' + esc(msg) + '</p>');
      first = first || $w;
    });
    if (first && first[0]) first[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  function btnLoading($b, on, t) { if (on) $b.data('h', $b.html()).prop('disabled', true).html('<i class="fa-solid fa-circle-notch fa-spin"></i> ' + (t || 'Working…')); else $b.prop('disabled', false).html($b.data('h')); }

  /* ---------- modals ---------- */
  function openModal(sel) { const $m = $(sel); $m.removeClass('hidden'); $('body').addClass('overflow-hidden'); setTimeout(() => $m.find('input:visible,select:visible,textarea:visible').first().trigger('focus'), 50); return $m; }
  function closeModal($m) { $m.addClass('hidden'); if (!$('.vk-modal:not(.hidden)').length) $('body').removeClass('overflow-hidden'); }
  $(document).on('click', '[data-modal-open]', function (e) { e.preventDefault(); openModal($(this).data('modal-open')); });
  $(document).on('click', '[data-modal-close]', function () { closeModal($(this).closest('.vk-modal')); });
  $(document).on('keydown', e => { if (e.key === 'Escape') $('.vk-modal:not(.hidden)').last().each(function () { closeModal($(this)); }); });

  /* ---------- generic CRUD modal ---------- */
  function fillForm($f, row) {
    $f[0].reset && $f[0].reset();
    $f.find('.vk-error').remove(); $f.find('.is-invalid').removeClass('is-invalid');
    $f.find('[name]').each(function () {
      const n = this.name; if (n === '_csrf') return;
      if (!(n in row)) { if (this.type !== 'hidden' || n === 'id') { if (this.type === 'checkbox') this.checked = false; else if (n === 'id') $(this).val(''); } return; }
      const v = row[n];
      if (this.type === 'checkbox') this.checked = String(v) === '1';
      else if (this.type === 'datetime-local' && v) $(this).val(String(v).replace(' ', 'T').slice(0, 16));
      else if ($(this).is('[data-party-search],[data-unit-search]') && v) { if (!$(this).find('option[value="' + v + '"]').length) $(this).append(new Option('#' + v, v, true, true)); $(this).val(String(v)).trigger('vk:sync'); }
      else if (this.type !== 'hidden' || n === 'id') $(this).val(v === null ? '' : v);
    });
  }
  $(document).on('click', '[data-crud-new]', function () { const $m = $($(this).data('crud-new')); const $f = $m.find('form'); const defaults = {}; $f.find('[name]').each(function () { defaults[this.name] = this.defaultValue; }); $f[0].reset(); $f.find('[name=id]').val(''); $m.find('[data-modal-title]').text($(this).data('title')); openModal($m); });
  $(document).on('click', '[data-crud-edit]', function () { const $m = $($(this).data('crud-edit')); fillForm($m.find('form'), $(this).data('row')); $m.find('[data-modal-title]').text($(this).data('title')); openModal($m); });

  /* ---------- AJAX forms ---------- */
  $(document).on('submit', 'form[data-ajax]', function (e) {
    e.preventDefault();
    const $f = $(this), $b = $f.find('button:not([type=button])').last();
    if ($f.data('confirm') && !window.confirm($f.data('confirm'))) return;
    btnLoading($b, true);
    const fd = new FormData(this);
    $.ajax({ url: $f.data('ajax'), method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
      .then(r => {
        btnLoading($b, false); toast(r.message || 'Saved');
        $f.trigger('vk:saved', [r]);
        if (r.redirect) location.href = r.redirect; else if (!$f.is('[data-stay]')) setTimeout(() => location.reload(), 450);
      })
      .catch(xhr => { btnLoading($b, false); const j = xhr.responseJSON || {}; showErrors($f, j.errors); toast(j.message || 'Could not save', 'error'); });
  });
  /* ---------- action buttons: data-action="url" [data-confirm] [data-prompt] ---------- */
  $(document).on('click', '[data-action]', async function (e) {
    e.preventDefault();
    const $b = $(this); const data = Object.assign({}, $b.data('payload') || {});
    if ($b.data('prompt')) {
      const r = await Swal.fire({ title: $b.data('prompt'), input: $b.data('prompt-type') || 'text', inputValue: $b.data('prompt-value') || '', showCancelButton: true, inputValidator: v => (!v && !$b.data('prompt-optional')) ? 'Required' : null });
      if (!r.isConfirmed) return; data[$b.data('prompt-name') || 'reason'] = r.value;
    } else if ($b.data('confirm')) { const ok = await confirm({ title: $b.data('confirm'), icon: $b.data('icon') || 'warning' }); if (!ok) return; }
    btnLoading($b, true, '');
    $.ajax({ url: $b.data('action'), method: 'POST', data, dataType: 'json' })
      .then(r => { toast(r.message || 'Done'); if (r.redirect) location.href = r.redirect; else if (!$b.is('[data-no-reload]')) setTimeout(() => location.reload(), 400); else btnLoading($b, false); $b.trigger('vk:done', [r]); })
      .catch(xhr => { btnLoading($b, false); toast((xhr.responseJSON || {}).message || 'Failed', 'error'); });
  });

  /* ---------- dropdowns, sidebar, theme ---------- */
  $(document).on('click', '[data-dd-toggle]', function (e) { e.stopPropagation(); const $d = $(this).closest('[data-dd]'); const open = $d.hasClass('is-open'); $('[data-dd]').removeClass('is-open'); $d.toggleClass('is-open', !open); });
  $(document).on('click', e => { if (!$(e.target).closest('[data-dd-menu]').length) $('[data-dd]').removeClass('is-open'); });
  $(document).on('click', '[data-sb-toggle]', function () {
    if (window.innerWidth >= 1024) { const c = !document.documentElement.classList.contains('sb-collapsed'); document.documentElement.classList.toggle('sb-collapsed', c); try { localStorage.setItem('vk-sb', c ? '0' : '1'); } catch (e) {} }
    else { $('html').toggleClass('sb-open'); $('#sb-overlay').toggleClass('hidden'); }
  });
  $(document).on('click', '[data-sb-close]', () => { $('html').removeClass('sb-open'); $('#sb-overlay').addClass('hidden'); });
  $(document).on('click', '[data-theme-toggle]', function () {
    const d = !document.documentElement.classList.contains('dark'); document.documentElement.classList.toggle('dark', d);
    try { localStorage.setItem('vk-theme', d ? 'dark' : 'light'); } catch (e) {}
    api('dashboard/theme', { theme: d ? 'dark' : 'light' }, { silent: true });
  });
  $(document).on('click', 'tr[data-href], [data-href]:not(a)', function (e) { if ($(e.target).closest('a,button,input,select,label').length) return; location.href = $(this).data('href'); });
  $(document).on('change', 'form[data-autosubmit] select, form[data-autosubmit] input[type=date], form[data-autosubmit] input[type=checkbox]', function () { $(this).closest('form').trigger('submit'); });
  $(document).on('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#global-search').trigger('focus'); } });

  /* ---------- search pickers (party / unit) ---------- */
  function picker($sel, endpoint, extra) {
    if ($sel.data('picker')) return; $sel.data('picker', 1);
    const $wrap = $('<div class="relative"></div>'); $sel.after($wrap); $sel.addClass('hidden').appendTo($wrap);
    const $in = $('<input type="text" class="vk-input" autocomplete="off">').attr('placeholder', $sel.find('option:first').text()).prependTo($wrap);
    const $res = $('<div class="select-search-results hidden"></div>').appendTo($wrap);
    const sync = () => { const t = $sel.find('option:selected'); $in.val(t.val() ? t.text() : ''); };
    sync(); $sel.on('vk:sync change', sync);
    let t, hl = -1;
    $in.on('input focus', function () {
      clearTimeout(t); const q = this.value;
      t = setTimeout(() => $.get(url('api.php', { r: endpoint }), Object.assign({ q }, extra($sel))).then(r => {
        hl = -1; $res.html((r.items || []).map(i => `<button type="button" data-id="${i.id}" data-label="${esc(i.label)}"><b>${esc(i.label)}</b>${i.sub ? `<span class="block text-xs text-muted">${esc(i.sub)}</span>` : ''}</button>`).join('') || '<p class="p-3 text-sm text-muted">No matches</p>').removeClass('hidden');
      }), 180);
    });
    $in.on('keydown', e => { const $b = $res.find('button'); if (e.key === 'ArrowDown') { hl = Math.min(hl + 1, $b.length - 1); } else if (e.key === 'ArrowUp') { hl = Math.max(hl - 1, 0); } else if (e.key === 'Enter' && hl > -1) { e.preventDefault(); $b.eq(hl).trigger('mousedown'); return; } else return; $b.removeClass('is-hl').eq(hl).addClass('is-hl'); e.preventDefault(); });
    $res.on('mousedown', 'button', function (e) { e.preventDefault(); const id = $(this).data('id'); if (!$sel.find('option[value="' + id + '"]').length) $sel.append(new Option($(this).data('label'), id)); $sel.val(String(id)).trigger('change'); $res.addClass('hidden'); });
    $in.on('blur', () => setTimeout(() => { $res.addClass('hidden'); if (!$in.val()) $sel.val('').trigger('change'); else sync(); }, 150));
  }
  function initPickers(scope) {
    $(scope || document).find('select[data-party-search]').each(function () { picker($(this), 'property/search-parties', $s => ({ role: $s.data('role') || '' })); });
    $(scope || document).find('select[data-unit-search]').each(function () { picker($(this), 'property/search-units', $s => ({ property_id: $s.data('property') || '' })); });
  }
  $(function () { initPickers(); });

  /* ---------- form toggles: party type (individual/company), ownership scope ---------- */
  function partyType($f) { const co = $f.find('[name=party_type]:checked').val() === 'company'; $f.find('[data-ind]').toggleClass('hidden', co); $f.find('[data-co]').toggleClass('hidden', !co); }
  $(document).on('change', '[name=party_type]', function () { partyType($(this).closest('form')); });
  $(document).on('click', '[data-crud-edit],[data-modal-open]', function () { const $m = $($(this).data('crud-edit') || $(this).data('modal-open')); setTimeout(() => { $m.find('form').each(function () { partyType($(this)); $(this).find('[data-scope]').trigger('change'); }); }, 0); });
  $(document).on('change', '[data-scope]', function () { const v = this.value, $f = $(this).closest('form'); $f.find('[data-scope-unit]').toggleClass('hidden', v !== 'unit'); $f.find('[data-scope-block]').toggleClass('hidden', v !== 'block'); });
  /* show / hide blocks: <select data-toggle-target="#x" data-toggle-value="a,b"> */
  $(document).on('change', '[data-show-when]', function () { const $f = $(this).closest('form'); const n = this.name, v = this.type === 'checkbox' ? (this.checked ? '1' : '0') : $f.find('[name="' + n + '"]:checked').val() || $(this).val();
    $f.find('[data-when-' + n + ']').each(function () { $(this).toggleClass('hidden', !String($(this).data('when-' + n)).split(',').includes(String(v))); }); });
  $(function () { $('[data-show-when]').trigger('change'); });

  /* ---------- "amount paid" history modal: <a data-paid='{"invoice_id":1}'> ---------- */
  function paidHistory(params, title) {
    return $.get(url('api.php', Object.assign({ r: 'billing/paid-history' }, params))).then(r => {
      const rows = (r.items || []).map(i => `<tr><td class="text-xs">${esc(i.paid_label)}</td><td>${i.payment_url ? `<a class="font-semibold text-accent" href="${i.payment_url}">${esc(i.receipt_no)}</a>` : '<span class="font-semibold text-violet-600">Credit</span>'}<span class="block text-[11px] text-muted">${esc(i.reference || (i.credit_id ? 'overpayment / prepayment' : i.method || ''))}${i.account ? ' · ' + esc(i.account) : ''}</span></td>
        <td class="text-sm"><a class="hover:text-accent" href="${i.invoice_url}">${esc(i.invoice_no)}</a><span class="block text-[11px] text-muted">${esc(i.bill_type)} · ${esc(i.description)}</span></td><td class="num font-bold">${esc(i.amount_label)}</td></tr>`).join('');
      Swal.fire({ title: title || 'Payment history', width: 760, showConfirmButton: false, showCloseButton: true,
        html: rows ? `<div class="text-left max-h-[60vh] overflow-y-auto"><table class="vk-table"><thead><tr><th>Paid</th><th>Receipt</th><th>Applied to</th><th class="num">Amount</th></tr></thead><tbody>${rows}</tbody><tfoot><tr><td colspan="3" class="font-bold">Total</td><td class="num font-extrabold">${esc(r.total)}</td></tr></tfoot></table></div>` : '<p class="text-muted py-6">No payments applied yet.</p>' });
    });
  }
  $(document).on('click', '[data-paid]', function (e) { e.preventDefault(); e.stopPropagation(); paidHistory($(this).data('paid'), $(this).data('paid-title')); });

  /* ---------- counts, heartbeat, flashes ---------- */
  function counts() { $.get(url('api.php', { r: 'dashboard/counts' })).then(r => { if (!r) return; $('[data-count=notif]').text(r.notifications > 99 ? '99+' : r.notifications).prop('hidden', !r.notifications); $('[data-count=online]').text(r.online).prop('hidden', !r.online); }).catch(() => {}); }
  $(function () { if ($('[data-count]').length) { counts(); setInterval(() => !document.hidden && counts(), 45000); } $('.vk-flash').each(function () { toast($(this).data('msg'), $(this).data('type') === 'error' ? 'error' : ($(this).data('type') === 'info' ? 'info' : 'success')); }); });

  /* ---------- tiny chart helper ---------- */
  function chart(id, cfg) { const el = document.getElementById(id); if (!el || !window.Chart) return null; const dark = document.documentElement.classList.contains('dark');
    Chart.defaults.color = dark ? '#94A3B8' : '#64748B'; Chart.defaults.borderColor = dark ? 'rgba(148,163,184,.15)' : 'rgba(100,116,139,.15)'; Chart.defaults.font.family = '"Plus Jakarta Sans", sans-serif';
    return new Chart(el, cfg); }

  return { url, api, toast, confirm, money, num, esc, showErrors, btnLoading, openModal, closeModal, fillForm, initPickers, chart, paidHistory };
})(jQuery);
