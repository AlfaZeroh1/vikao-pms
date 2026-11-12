<?php
/** UI building blocks for PMS pages (Tailwind classes, server-rendered). */

function ui_stat(string $icon, string $label, string $value, string $tone = 'accent', ?string $sub = null, ?string $href = null): string {
    $tones = ['accent' => 'bg-accent/10 text-accent', 'green' => 'bg-emerald-500/10 text-emerald-600', 'red' => 'bg-rose-500/10 text-rose-600', 'blue' => 'bg-sky-500/10 text-sky-600', 'violet' => 'bg-violet-500/10 text-violet-600', 'slate' => 'bg-slate-500/10 text-slate-500'];
    $tag = $href ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="vk-panel p-4 flex items-center gap-3 ' . ($href ? 'hover:border-accent/50 transition' : '') . '"><span class="grid h-11 w-11 shrink-0 place-items-center rounded-xl ' . ($tones[$tone] ?? $tones['accent']) . '"><i class="fa-solid ' . e($icon) . '"></i></span>'
        . '<div class="min-w-0"><p class="text-lg 2xl:text-xl font-extrabold tracking-tight whitespace-nowrap">' . $value . '</p><p class="text-xs text-muted font-semibold truncate">' . e($label) . ($sub ? ' · <span class="font-normal">' . $sub . '</span>' : '') . '</p></div></' . ($href ? 'a' : 'div') . '>';
}
function ui_options(array $rows, string $valKey, string $labelKey, $selected = null, ?string $placeholder = null): string {
    $h = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    $sel = is_array($selected) ? array_map('strval', $selected) : [(string)$selected];
    foreach ($rows as $r) { $v = is_array($r) ? $r[$valKey] : $r; $l = is_array($r) ? $r[$labelKey] : $r; $h .= '<option value="' . e($v) . '"' . (in_array((string)$v, $sel, true) ? ' selected' : '') . '>' . e($l) . '</option>'; }
    return $h;
}
function ui_kv_options(array $map, $selected = null, ?string $placeholder = null): string {
    $h = $placeholder !== null ? '<option value="">' . e($placeholder) . '</option>' : '';
    foreach ($map as $k => $v) $h .= '<option value="' . e($k) . '"' . ((string)$selected === (string)$k ? ' selected' : '') . '>' . e($v) . '</option>';
    return $h;
}
function ui_empty(string $icon, string $title, string $text = '', string $cta = ''): string {
    return '<div class="py-14 text-center"><div class="mx-auto grid h-14 w-14 place-items-center rounded-2xl bg-accent/10 text-accent text-xl"><i class="fa-solid ' . e($icon) . '"></i></div><p class="mt-3 font-bold">' . e($title) . '</p>'
        . ($text ? '<p class="text-sm text-muted max-w-md mx-auto">' . e($text) . '</p>' : '') . ($cta ? '<div class="mt-4">' . $cta . '</div>' : '') . '</div>';
}
function ui_tabs(array $tabs, string $active, string $param = 'tab', array $keep = []): string {
    $h = '<div class="flex gap-1 overflow-x-auto no-scrollbar border-b border-line mb-5">';
    foreach ($tabs as $k => $t) {
        [$label, $icon, $count] = array_pad((array)$t, 3, null);
        $h .= '<a href="' . e(page($_GET['r'] ?? '', $keep + [$param => $k])) . '" class="vk-tab ' . ($k === $active ? 'is-active' : '') . '">' . ($icon ? '<i class="fa-solid ' . $icon . '"></i>' : '') . e($label)
            . ($count !== null ? '<span class="vk-tab-count">' . $count . '</span>' : '') . '</a>';
    }
    return $h . '</div>';
}
/** Pagination: returns [limit, offset, html] */
function ui_paginate(int $total, int $per = 25): array {
    $page = max(1, (int)($_GET['page'] ?? 1)); $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $h = '';
    if ($pages > 1) {
        $q = $_GET; $link = function ($p) use ($q) { $q['page'] = $p; return '?' . http_build_query($q); };
        $h = '<div class="flex items-center justify-between gap-2 px-4 py-3 border-t border-line text-sm"><span class="text-muted">' . num($total) . ' records · page ' . $page . ' of ' . $pages . '</span><div class="flex gap-1">';
        if ($page > 1) $h .= '<a class="vk-btn-ghost !py-1.5 !px-3" href="' . e($link($page - 1)) . '"><i class="fa-solid fa-chevron-left"></i></a>';
        foreach (array_unique([1, max(1, $page - 1), $page, min($pages, $page + 1), $pages]) as $p) $h .= '<a class="' . ($p === $page ? 'vk-btn-dark' : 'vk-btn-ghost') . ' !py-1.5 !px-3" href="' . e($link($p)) . '">' . $p . '</a>';
        if ($page < $pages) $h .= '<a class="vk-btn-ghost !py-1.5 !px-3" href="' . e($link($page + 1)) . '"><i class="fa-solid fa-chevron-right"></i></a>';
        $h .= '</div></div>';
    }
    return [$per, ($page - 1) * $per, $h];
}
/** Filter bar wrapper: echo ui_filter_open(); …fields…; echo ui_filter_close(); */
function ui_filter_open(): string { $r = e($_GET['r'] ?? ''); return '<form method="get" class="vk-panel p-3 mb-4 flex flex-wrap items-end gap-2" data-autosubmit><input type="hidden" name="r" value="' . $r . '">'; }
function ui_filter_close(): string { return '<button class="vk-btn-dark !py-2.5"><i class="fa-solid fa-filter"></i>Filter</button><a href="' . e(page($_GET['r'] ?? '')) . '" class="vk-btn-ghost !py-2.5">Reset</a></form>'; }
function ui_field(string $label, string $control, string $cls = ''): string { return '<label class="block ' . $cls . '" data-field-wrap><span class="vk-label">' . $label . '</span>' . $control . '</label>'; }
function ui_input(string $name, $value = '', string $type = 'text', string $extra = ''): string { return '<input type="' . $type . '" name="' . $name . '" value="' . e($value) . '" class="vk-input" ' . $extra . '>'; }
function ui_select(string $name, string $options, string $extra = ''): string { return '<select name="' . $name . '" class="vk-select" ' . $extra . '>' . $options . '</select>'; }
function ui_money_cell($v, bool $red = false): string { return '<span class="font-semibold tabular-nums ' . ($red && (float)$v > 0 ? 'text-rose-600' : '') . '">' . money($v, false) . '</span>'; }
function ui_avatar(string $name, string $size = 'h-9 w-9'): string { return '<span class="grid ' . $size . ' shrink-0 place-items-center rounded-xl bg-gradient-to-br from-slate-600 to-slate-800 text-[11px] font-bold text-white">' . e(initials($name)) . '</span>'; }
function ui_timeline(array $items): string {
    // items: [icon, tone, title, body, when, who]
    $h = '<ol class="relative border-l-2 border-line ml-3 space-y-5">';
    foreach ($items as $it) {
        [$icon, $tone, $title, $body, $when, $who] = array_pad($it, 6, null);
        $h .= '<li class="ml-6"><span class="absolute -left-[15px] grid h-7 w-7 place-items-center rounded-full ring-4 ring-surface ' . (PILL_TONES[$tone] ?? PILL_TONES['slate']) . '"><i class="fa-solid ' . e($icon) . ' text-[11px]"></i></span>'
            . '<p class="font-semibold text-sm">' . $title . '</p>' . ($body ? '<div class="text-sm text-muted">' . $body . '</div>' : '') . '<p class="text-[11px] text-muted mt-0.5">' . e($when) . ($who ? ' · ' . e($who) : '') . '</p></li>';
    }
    return $h . '</ol>';
}
function properties_for_select(): array { return all("SELECT id, name, code FROM prpt_properties WHERE deleted_at IS NULL AND " . scope_sql('id') . " ORDER BY name"); }
function bill_types_for_select(): array { return array_values(bill_types()); }
function staff_for_select(): array { return all("SELECT id, CONCAT(first_name, ' ', COALESCE(last_name,'')) AS name FROM sys_users WHERE status = 'active' AND deleted_at IS NULL ORDER BY first_name"); }
/** Party picker: <select data-party-search> fed by AJAX */
function ui_party_picker(string $name, ?int $selectedId = null, string $role = '', string $extra = ''): string {
    $opt = '<option value="">Search name, phone, ID…</option>';
    if ($selectedId && ($p = one("SELECT id, display_name, phone FROM prpt_parties WHERE id = ?", [$selectedId]))) $opt .= '<option value="' . $p['id'] . '" selected>' . e($p['display_name'] . ($p['phone'] ? ' · ' . $p['phone'] : '')) . '</option>';
    return '<select name="' . $name . '" class="vk-select" data-party-search data-role="' . e($role) . '" ' . $extra . '>' . $opt . '</select>';
}
function ui_unit_picker(string $name, ?int $selectedId = null, string $extra = ''): string {
    $opt = '<option value="">Search unit / property…</option>';
    if ($selectedId && ($u = one("SELECT u.id, u.unit_no, p.name FROM prpt_units u JOIN prpt_properties p ON p.id = u.property_id WHERE u.id = ?", [$selectedId]))) $opt .= '<option value="' . $u['id'] . '" selected>' . e($u['unit_no'] . ' · ' . $u['name']) . '</option>';
    return '<select name="' . $name . '" class="vk-select" data-unit-search ' . $extra . '>' . $opt . '</select>';
}
/** Modal shell. Body is a form posting to $action via AJAX. */
function ui_modal(string $id, string $title, string $body, string $action = '', string $submit = 'Save', string $size = 'max-w-2xl', string $icon = 'fa-pen'): string {
    $form = $action ? '<form data-ajax="' . e($action) . '" class="contents" enctype="multipart/form-data">' : '<div class="contents">';
    return '<div id="' . e($id) . '" class="vk-modal hidden" role="dialog" aria-modal="true"><div class="vk-modal-backdrop" data-modal-close></div>'
        . '<div class="vk-modal-card ' . $size . '">' . $form . '<div class="flex items-center gap-3 px-6 py-4 border-b border-line"><span class="grid h-9 w-9 place-items-center rounded-xl bg-accent/10 text-accent"><i class="fa-solid ' . e($icon) . '"></i></span><h3 class="font-display text-lg font-bold" data-modal-title>' . e($title) . '</h3>'
        . '<button type="button" class="ml-auto vk-icon-btn" data-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></div>'
        . '<div class="px-6 py-5 max-h-[70vh] overflow-y-auto space-y-4">' . $body . '</div>'
        . ($action ? '<div class="flex justify-end gap-2 px-6 py-4 border-t border-line bg-surface2/40"><button type="button" class="vk-btn-ghost" data-modal-close>Cancel</button><button class="vk-btn-accent"><i class="fa-solid fa-check"></i>' . e($submit) . '</button></div>' : '')
        . ($action ? '</form>' : '</div>') . '</div></div>';
}
function csv_download(string $filename, array $header, array $rows): never {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, $header);
    foreach ($rows as $r) fputcsv($out, array_values($r));
    fclose($out); exit;
}
/** Queue a <script> block to print after the core libraries. */
function page_script(string $html): void { $GLOBALS['page_scripts'] = ($GLOBALS['page_scripts'] ?? '') . $html; }
