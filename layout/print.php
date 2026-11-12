<?php /** Print / PDF layout for statements, invoices, agreements. */ ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= e($title) ?></title><meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/print.css') ?>"></head>
<body>
<div class="toolbar no-print"><button onclick="window.print()">🖨 Print / Save as PDF</button><button onclick="history.back()">← Back</button></div>
<div class="sheet">
  <header class="doc-head">
    <div><img src="<?= asset('img/logo-mark.png') ?>" alt="" class="logo"><div><b class="brand">vikao<span>.</span></b><br><small><?= e(setting('company_name')) ?></small></div></div>
    <div class="right"><small><?= e(setting('company_address')) ?><br><?= e(setting('company_phone')) ?> · <?= e(setting('company_email')) ?><br>PIN <?= e(setting('company_kra_pin')) ?></small></div>
  </header>
  <?= $content ?>
  <footer class="doc-foot">Generated <?= date('j M Y, H:i') ?> by <?= e(staff_name()) ?> · Vikao PMS</footer>
</div>
</body></html>
