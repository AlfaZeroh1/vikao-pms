<?php
require_once __DIR__ . '/_partials.php';
$title = 'Documents';
$subtitle = 'Every uploaded and generated document — agreements, title deeds, IDs, notices';
$f = ['type' => (string)($_GET['type'] ?? ''), 'owner' => (string)($_GET['owner'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'property_id' => (int)($_GET['property_id'] ?? 0)];
$w = ['(d.property_id IS NULL OR ' . scope_sql('d.property_id') . ')']; $p = [];
if ($f['type']) { $w[] = 'd.doc_type = :t'; $p['t'] = $f['type']; }
if ($f['owner']) { $w[] = 'd.owner_type = :o'; $p['o'] = $f['owner']; }
if ($f['property_id']) { $w[] = 'd.property_id = :p'; $p['p'] = $f['property_id']; }
if ($f['q'] !== '') { $w[] = '(d.title LIKE :q OR pt.display_name LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
$where = implode(' AND ', $w);
$total = (int)val("SELECT COUNT(*) FROM prpt_documents d LEFT JOIN prpt_parties pt ON pt.id = d.party_id WHERE $where", $p);
[$lim, $off, $pager] = ui_paginate($total, 40);
$rows = all("SELECT d.*, pt.display_name AS party, pr.name AS property FROM prpt_documents d LEFT JOIN prpt_parties pt ON pt.id = d.party_id LEFT JOIN prpt_properties pr ON pr.id = d.property_id WHERE $where ORDER BY d.created_at DESC LIMIT $lim OFFSET $off", $p);
$types = ['lease' => 'Lease', 'title_deed' => 'Title deed', 'sale_agreement' => 'Sale agreement', 'mgmt_agreement' => 'Management agreement', 'id_copy' => 'ID copy', 'kra' => 'KRA', 'notice' => 'Notice', 'statement' => 'Statement', 'photo' => 'Photo', 'other' => 'Other'];
$ownerLink = fn($d) => match ($d['owner_type']) { 'property' => page('property/property', ['id' => $d['owner_id']]), 'unit' => page('property/unit', ['id' => $d['owner_id']]), 'party' => page('property/party', ['id' => $d['owner_id']]), 'lease' => page('billing/lease', ['id' => $d['owner_id']]), 'ticket' => page('crm/ticket', ['id' => $d['owner_id']]), default => '#' };
?>
<?= ui_filter_open() ?>
  <?= ui_field('Search', ui_input('q', $f['q'], 'search', 'placeholder="Title or person"'), 'w-60') ?>
  <?= ui_field('Type', ui_select('type', ui_kv_options($types, $f['type'], 'Any')), 'w-44') ?>
  <?= ui_field('Attached to', ui_select('owner', ui_kv_options(['property' => 'Property', 'unit' => 'Unit', 'party' => 'Person', 'lease' => 'Account / lease', 'ticket' => 'Ticket', 'sale' => 'Sale'], $f['owner'], 'Anything')), 'w-40') ?>
  <?= ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', $f['property_id'], 'All')), 'w-52') ?>
<?= ui_filter_close() ?>
<div class="vk-panel overflow-hidden"><div class="overflow-x-auto"><table class="vk-table">
  <thead><tr><th>Document</th><th>Type</th><th>Attached to</th><th>Person</th><th>Portal</th><th>Signed</th><th>Added</th></tr></thead><tbody>
  <?php foreach ($rows as $d): $href = $d['file_path'] ? file_url($d['file_path']) : page('property/document', ['id' => $d['id']]); ?>
    <tr><td><a href="<?= e($href) ?>" target="_blank" class="font-semibold hover:text-accent"><i class="fa-solid <?= $d['file_path'] ? 'fa-paperclip' : 'fa-file-signature' ?> text-accent mr-1.5"></i><?= e($d['title']) ?></a></td>
      <td><?= e($types[$d['doc_type']] ?? ucfirst($d['doc_type'])) ?></td>
      <td><a class="text-sm hover:text-accent" href="<?= e($ownerLink($d)) ?>"><?= e(ucfirst($d['owner_type'])) ?> #<?= (int)$d['owner_id'] ?></a><span class="block text-xs text-muted"><?= e($d['property'] ?? '') ?></span></td>
      <td class="text-sm"><?= $d['party_id'] ? '<a class="hover:text-accent" href="' . e(page('property/party', ['id' => $d['party_id']])) . '">' . e($d['party']) . '</a>' : '—' ?></td>
      <td><?= $d['visible_in_portal'] ? '<i class="fa-solid fa-eye text-emerald-500"></i>' : '<i class="fa-solid fa-eye-slash text-muted"></i>' ?></td>
      <td class="text-xs"><?= $d['signed_at'] ? fdate($d['signed_at']) . '<span class="block text-muted">' . e($d['signed_by_name']) . '</span>' : '—' ?></td>
      <td class="text-xs"><?= fdate($d['created_at']) ?></td></tr>
  <?php endforeach; if (!$rows) echo '<tr><td colspan="7">' . ui_empty('fa-folder-open', 'No documents found') . '</td></tr>'; ?>
  </tbody></table></div><?= $pager ?></div>
