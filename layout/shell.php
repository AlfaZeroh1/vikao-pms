<?php
/** App shell: sidebar + topbar + content. Vars: $title, $subtitle, $actions, $content, $route, $me, $crumbs */
$section = $meta['section'] ?? '';
?><!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · Vikao PMS</title>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>"><meta name="base-url" content="<?= e(root_path()) ?>">
  <link rel="icon" href="<?= asset('img/favicon.png') ?>">
  <script>(function(){var p=<?= json_encode($me['theme']) ?>;try{p=p!=='system'?p:(localStorage.getItem('vk-theme')||'system')}catch(e){}document.documentElement.classList.toggle('dark',p==='dark'||(p==='system'&&matchMedia('(prefers-color-scheme: dark)').matches));try{if(localStorage.getItem('vk-sb')==='0')document.documentElement.classList.add('sb-collapsed')}catch(e){}})();</script>
  <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <script src="https://cdn.tailwindcss.com/3.4.16"></script>
  <script>tailwind.config={darkMode:'class',theme:{extend:{fontFamily:{sans:['"Plus Jakarta Sans"','system-ui','sans-serif'],display:['Poppins','"Plus Jakarta Sans"','sans-serif']},colors:['canvas','surface','surface2','ink','inksoft','muted','line','accent','accentink','nav','navink'].reduce(function(o,k){o[k]='rgb(var(--vk-'+k+') / <alpha-value>)';return o},{}),boxShadow:{soft:'0 1px 2px rgb(15 23 42 / .04), 0 8px 24px -12px rgb(15 23 42 / .18)',lift:'0 20px 40px -20px rgb(15 23 42 / .35)'},borderRadius:{'4xl':'2rem'}}}};</script>
  <link rel="stylesheet" href="<?= asset('css/pms.css') ?>">
</head>
<body class="h-full bg-canvas text-ink font-sans antialiased">
<div class="flex min-h-full">
  <!-- ================= SIDEBAR ================= -->
  <aside id="sidebar" class="vk-sidebar">
    <div class="flex items-center gap-3 h-16 px-4 border-b border-white/10 shrink-0">
      <img src="<?= asset('img/logo-mark.svg') ?>" class="h-9 w-9 rounded-xl" alt="">
      <div class="sb-label leading-tight"><p class="font-display font-bold text-white text-lg">vikao<span class="text-amber-400">.</span></p><p class="text-[10px] uppercase tracking-[.2em] text-slate-400">Property OS</p></div>
    </div>
    <nav class="flex-1 overflow-y-auto py-3 px-2.5 space-y-1 no-scrollbar" aria-label="Main">
      <?php foreach (PMS_MENU as [$sec, $secIcon, $items]):
        $visible = array_filter($items, fn($it) => can($it[2]));
        if (!$visible) continue;
        $open = $sec === $section || $sec === 'Overview'; ?>
        <?php if ($secIcon): ?>
          <details class="sb-group" <?= $open ? 'open' : '' ?>>
            <summary class="sb-sec"><i class="fa-solid <?= $secIcon ?> w-5 text-center"></i><span class="sb-label flex-1"><?= e($sec) ?></span><i class="sb-label fa-solid fa-chevron-down text-[9px] opacity-50 sb-chev"></i></summary>
            <div class="mt-0.5 space-y-0.5">
        <?php endif; ?>
        <?php foreach ($visible as $r => [$label, $icon, $perm]): ?>
          <a href="<?= e(page($r)) ?>" class="sb-link <?= $route === $r ? 'is-active' : '' ?>" title="<?= e($label) ?>"><i class="fa-solid <?= $icon ?> w-5 text-center"></i><span class="sb-label"><?= e($label) ?></span></a>
        <?php endforeach; ?>
        <?php if ($secIcon): ?></div></details><?php endif; ?>
      <?php endforeach; ?>
    </nav>
    <div class="p-3 border-t border-white/10 shrink-0">
      <a href="<?= e(page('dashboard/profile')) ?>" class="flex items-center gap-3 rounded-xl p-2 hover:bg-white/5">
        <?= ui_avatar(staff_name($me)) ?>
        <span class="sb-label min-w-0"><span class="block text-sm font-semibold text-white truncate"><?= e(staff_name($me)) ?></span><span class="block text-[11px] text-slate-400 truncate"><?= e($me['role_name']) ?></span></span>
      </a>
    </div>
  </aside>
  <div id="sb-overlay" class="fixed inset-0 z-30 bg-slate-950/50 hidden lg:hidden" data-sb-close></div>

  <!-- ================= MAIN ================= -->
  <div class="flex-1 min-w-0 flex flex-col">
    <header class="sticky top-0 z-20 h-16 bg-canvas/85 backdrop-blur-xl border-b border-line flex items-center gap-2 px-3 sm:px-5">
      <button type="button" class="vk-icon-btn" data-sb-toggle aria-label="Toggle menu"><i class="fa-solid fa-bars-staggered"></i></button>
      <a href="<?= e(page('dashboard/search')) ?>" class="vk-icon-btn sm:hidden" aria-label="Search"><i class="fa-solid fa-magnifying-glass"></i></a>
      <form action="<?= e(root_path() . '/') ?>" class="relative flex-1 max-w-xl hidden sm:block" role="search">
        <input type="hidden" name="r" value="dashboard/search">
        <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-muted text-sm"></i>
        <input name="q" value="<?= e($route === 'dashboard/search' ? ($_GET['q'] ?? '') : '') ?>" class="w-full rounded-xl border border-line bg-surface pl-10 pr-16 py-2.5 text-sm outline-none focus:border-accent" placeholder="Search units, tenants, invoices, receipts, tickets…" id="global-search" autocomplete="off">
        <kbd class="hidden sm:block absolute right-3 top-1/2 -translate-y-1/2 rounded-md border border-line px-1.5 text-[10px] text-muted">Ctrl K</kbd>
      </form>
      <div class="ml-auto flex items-center gap-1">
        <div class="relative" data-dd>
          <button type="button" class="vk-btn-accent !py-2 !px-3 text-sm" data-dd-toggle><i class="fa-solid fa-plus"></i><span class="hidden sm:inline">New</span></button>
          <div class="vk-dd-menu right-0 w-60" data-dd-menu>
            <?php foreach ([['billing/lease-new', 'fa-file-contract', 'Billing account / lease', 'billing.leases.manage'], ['billing/invoice-edit', 'fa-file-invoice', 'Manual invoice', 'billing.invoices.create'],
                            ['finance/payments', 'fa-money-bill-transfer', 'Record payment', 'finance.payments.create', 'new=1'], ['property/parties', 'fa-user-plus', 'Owner / tenant', 'property.parties.manage', 'new=1'],
                            ['crm/tickets', 'fa-ticket', 'Ticket', 'crm.tickets.manage', 'new=1'], ['finance/expenses', 'fa-receipt', 'Expense', 'finance.expenses.manage', 'new=1'],
                            ['shortstay/bookings', 'fa-suitcase-rolling', 'Booking', 'shortstay.manage', 'new=1'], ['sales/leads', 'fa-user-plus', 'Sales lead', 'sales.leads.manage', 'new=1']] as $q):
              if (!can($q[3])) continue; parse_str($q[4] ?? '', $qq); ?>
              <a href="<?= e(page($q[0], $qq)) ?>" class="vk-dd-item"><i class="fa-solid <?= $q[1] ?> w-5 text-accent"></i><?= e($q[2]) ?></a>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" class="vk-icon-btn" data-theme-toggle title="Theme"><i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:inline"></i></button>
        <?php if (can('admin.online.view')): ?><a href="<?= e(page('admin/online')) ?>" class="vk-icon-btn" title="Who is online"><i class="fa-solid fa-signal"></i><span class="vk-dot-badge !bg-emerald-500" data-count="online" hidden></span></a><?php endif; ?>
        <a href="<?= e(page('dashboard/notifications')) ?>" class="vk-icon-btn" title="Notifications"><i class="fa-regular fa-bell"></i><span class="vk-dot-badge" data-count="notif" hidden></span></a>
        <div class="relative" data-dd>
          <button type="button" class="vk-icon-btn" data-dd-toggle aria-label="Account"><i class="fa-regular fa-circle-user"></i></button>
          <div class="vk-dd-menu right-0 w-56" data-dd-menu>
            <div class="px-3 py-2 border-b border-line mb-1"><p class="font-semibold text-sm"><?= e(staff_name($me)) ?></p><p class="text-xs text-muted"><?= e($me['email']) ?></p></div>
            <a href="<?= e(page('dashboard/profile')) ?>" class="vk-dd-item"><i class="fa-solid fa-user-pen w-5"></i>My profile</a>
            <a href="<?= e(rtrim((string)config('app.portal_url'), '/')) ?>" target="_blank" class="vk-dd-item"><i class="fa-solid fa-mobile-screen w-5"></i>Open self-service portal</a>
            <a href="<?= e(rtrim((string)config('ecom_db.site_url'), '/')) ?>" target="_blank" class="vk-dd-item"><i class="fa-solid fa-store w-5"></i>Open vikao.io</a>
            <form method="post" action="<?= e(url('logout.php')) ?>"><?= csrf_field() ?><button class="vk-dd-item w-full text-rose-600"><i class="fa-solid fa-arrow-right-from-bracket w-5"></i>Sign out</button></form>
          </div>
        </div>
      </div>
    </header>

    <main class="flex-1 px-3 sm:px-6 py-5 sm:py-7 max-w-[1600px] w-full mx-auto">
      <?php if ($title && $layout !== 'bare'): ?>
      <div class="flex flex-wrap items-end gap-3 mb-6">
        <div class="min-w-0">
          <?php if ($crumbs): ?><nav class="text-xs text-muted mb-1 flex flex-wrap items-center gap-1.5"><?php foreach ($crumbs as [$cl, $ch]): ?><a href="<?= e($ch) ?>" class="hover:text-accent"><?= e($cl) ?></a><i class="fa-solid fa-chevron-right text-[8px]"></i><?php endforeach; ?></nav><?php endif; ?>
          <h1 class="font-display text-2xl sm:text-[1.7rem] font-bold tracking-tight"><?= e($title) ?></h1>
          <?php if ($subtitle): ?><p class="text-sm text-muted mt-0.5"><?= $subtitle ?></p><?php endif; ?>
        </div>
        <?php if ($actions): ?><div class="ml-auto flex flex-wrap items-center gap-2"><?= $actions ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <?php foreach (take_flashes() as $f): ?><div class="vk-flash" data-type="<?= e($f['type']) ?>" data-msg="<?= e($f['msg']) ?>" hidden></div><?php endforeach; ?>
      <?= $content ?>
    </main>
    <footer class="px-6 py-4 text-xs text-muted border-t border-line flex flex-wrap gap-2 justify-between"><span>© <?= date('Y') ?> <?= e(setting('company_name', 'Vikao')) ?> · Vikao PMS</span><span><?= e($me['role_name']) ?> · <?= date('D j M Y, H:i') ?></span></footer>
  </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.8/sweetalert2.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="<?= asset('js/pms.js') ?>"></script>
<?= $GLOBALS['page_scripts'] ?? '' ?>
</body>
</html>
