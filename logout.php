<?php
define('VK_APP', 'admin');
require __DIR__ . '/core/bootstrap.php';
if (is_post()) { verify_csrf(); staff_logout(); }
redirect(url('login.php'));
