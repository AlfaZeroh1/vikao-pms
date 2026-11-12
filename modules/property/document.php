<?php
/** Generated document viewer (print layout) — e.g. auto-generated lease agreements */
$d = one("SELECT d.*, pt.display_name AS party FROM prpt_documents d LEFT JOIN prpt_parties pt ON pt.id = d.party_id WHERE d.id = ?", [(int)($_GET['id'] ?? 0)]);
if (!$d) { echo ui_empty('fa-file', 'Document not found'); return; }
if ($d['file_path']) redirect(file_url($d['file_path']));
$title = $d['title'];
$layout = 'print';
$canSign = can('property.documents.manage') && !$d['signed_at'];
?>
<div class="doc-body"><?= $d['body_html'] ?></div>
<div class="sign-grid">
  <div><p>Signed by the Landlord / Agent</p><div class="sign-line"></div><small>Name &amp; date</small></div>
  <div><p>Signed by the Tenant / Purchaser</p><div class="sign-line"><?= $d['signed_at'] ? '<em>' . e($d['signed_by_name']) . ' · ' . fdate($d['signed_at']) . '</em>' : '' ?></div><small>Name &amp; date</small></div>
</div>
<?php if ($canSign): ?>
<div class="no-print" style="margin-top:24px;text-align:center">
  <form method="post" action="<?= e(url('api.php', ['r' => 'property/sign-document', 'id' => $d['id']])) ?>" onsubmit="event.preventDefault();const n=prompt('Name of the person who signed (as on ID):','<?= e(addslashes($d['party'] ?? '')) ?>');if(!n)return;fetch(this.action,{method:'POST',headers:{'X-CSRF-Token':'<?= e(csrf_token()) ?>','X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body:'name='+encodeURIComponent(n)}).then(()=>location.reload())">
    <button type="submit">✍ Mark as signed</button>
  </form>
</div>
<?php endif;
