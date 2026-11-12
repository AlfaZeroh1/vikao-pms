<?php
$u = (string)val("SELECT uuid FROM fin_payments WHERE id = ? OR uuid = ?", [(int)($_GET['id'] ?? 0), (string)($_GET['id'] ?? '')]);
redirect(page('finance/consume', $u ? ['payment' => $u] : []));
