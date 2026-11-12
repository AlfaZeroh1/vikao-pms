<?php
define('VK_APP', 'admin');
require __DIR__ . '/core/bootstrap.php';
if (staff()) redirect(url(''));
$error = null;
if (is_post()) {
    verify_csrf();
    if (!rate_limit('staff_login:' . client_ip(), 10, 600)) $error = 'Too many attempts. Try again in 10 minutes.';
    elseif (staff_login((string)input('login'), (string)($_POST['password'] ?? ''))) { $to = $_SESSION['intended'] ?? url(''); unset($_SESSION['intended']); redirect($to); }
    else $error = 'Wrong username / email or password.';
}
?><!doctype html><html lang="en" class="h-full"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sign in · Vikao PMS</title>
<link rel="icon" href="<?= asset('img/favicon.png') ?>"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Poppins:wght@700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"><script src="https://cdn.tailwindcss.com/3.4.16"></script>
<script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['"Plus Jakarta Sans"','sans-serif'],display:['Poppins','sans-serif']},colors:['canvas','surface','surface2','ink','inksoft','muted','line','accent','accentink','nav','navink'].reduce(function(o,k){o[k]='rgb(var(--vk-'+k+') / <alpha-value>)';return o},{})}}};if(matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.classList.add('dark');</script>
<link rel="stylesheet" href="<?= asset('css/pms.css') ?>"></head>
<body class="h-full bg-canvas text-ink font-sans">
<div class="min-h-full grid lg:grid-cols-2">
  <div class="hidden lg:flex relative overflow-hidden vk-hero text-white p-12 flex-col justify-between">
    <div class="absolute inset-0 vk-grid-bg opacity-60"></div>
    <div class="relative flex items-center gap-3"><img src="<?= asset('img/logo-mark.svg') ?>" class="h-12 w-12 rounded-2xl" alt=""><span class="font-display text-2xl font-bold">vikao<span class="text-amber-400">.</span> <span class="text-sm font-medium text-slate-400 ml-1">Property OS</span></span></div>
    <div class="relative max-w-md">
      <h1 class="font-display text-4xl font-bold leading-tight">Every unit, every shilling, every request — <span class="text-amber-400">in one place.</span></h1>
      <ul class="mt-8 space-y-3 text-slate-300 text-sm">
        <li><i class="fa-solid fa-circle-check text-amber-400 mr-2"></i>Billing, arrears & M-Pesa reconciliation</li>
        <li><i class="fa-solid fa-circle-check text-amber-400 mr-2"></i>Owner remittances & QuickBooks exports</li>
        <li><i class="fa-solid fa-circle-check text-amber-400 mr-2"></i>CRM, short stays, sales & the vikao.io marketplace</li>
      </ul>
    </div>
    <p class="relative text-xs text-slate-500">© <?= date('Y') ?> Vikao</p>
  </div>
  <div class="flex items-center justify-center p-6">
    <form method="post" class="w-full max-w-sm">
      <?= csrf_field() ?>
      <img src="<?= asset('img/logo-mark.svg') ?>" class="h-12 w-12 rounded-2xl lg:hidden mb-6" alt="">
      <h2 class="font-display text-3xl font-bold">Staff sign in</h2>
      <p class="text-muted mt-1 mb-6">Welcome back to Vikao PMS.</p>
      <?php if ($error): ?><div class="vk-alert-error mb-4"><?= e($error) ?></div><?php endif; ?>
      <label class="block mb-4"><span class="vk-label">Username or email</span><input name="login" value="<?= e(input('login', '')) ?>" class="vk-input" autofocus autocomplete="username"></label>
      <label class="block mb-6"><span class="vk-label">Password</span><input type="password" name="password" class="vk-input" autocomplete="current-password"></label>
      <button class="vk-btn-accent w-full !py-3.5"><i class="fa-solid fa-arrow-right-to-bracket"></i>Sign in</button>
      <p class="mt-6 text-xs text-muted text-center">Owners & tenants: use the <a class="text-accent font-semibold" href="<?= e(rtrim((string)config('app.portal_url'), '/')) ?>">self-service portal</a>.</p>
    </form>
  </div>
</div>
</body></html>
