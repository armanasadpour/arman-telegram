<?php
/** @var string $title @var string $bodyClass @var string $description @var ?array $city @var ?array $user @var bool $isAdmin @var int $unread @var string $siteName @var string $canonical @var ?array $jsonld @var string $ogImage @var string $robots */
$page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$nav = [
    ['index.php', 'صفحه اصلی', ['index.php']],
    ['index.php?view=doctors', 'نوبت‌دهی پزشکان', ['doctor.php']],
    ['specialties.php', 'تخصص‌ها', ['specialties.php']],
    ['centers.php', 'مراکز درمانی', ['centers.php', 'clinic.php']],
    ['articles.php', 'مقالات سلامت', ['articles.php', 'article.php']],
    [page_url('patient-guide'), 'راهنما', ['page.php']],
];
$base = app_base_url();
$adminShell = str_contains($bodyClass, 'admin-page');
// 1.0.0-rc.15: the administration panel installs as its own app (own id, start page, name and icon);
// with the site manifest the browser saw it as the patient app and opened index.php.
$adminApp = $adminShell || $page === 'admin.php';
?><!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<?php if (needs_base_href()): ?><base href="<?= esc($base) ?>"><?php endif; ?>
<title><?= esc($title) ?> | <?= esc($siteName) ?></title>
<meta name="description" content="<?= esc($description) ?>">
<?php if ($robots !== ''): ?><meta name="robots" content="<?= esc($robots) ?>"><?php endif; ?>
<?php if ($canonical !== ''): ?><link rel="canonical" href="<?= esc($base . $canonical) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:locale" content="fa_IR">
<meta property="og:site_name" content="<?= esc($siteName) ?>">
<meta property="og:title" content="<?= esc($title) ?>">
<meta property="og:description" content="<?= esc($description) ?>">
<meta property="og:image" content="<?= esc($base . ($ogImage !== '' ? $ogImage : 'assets/icons/icon-512.png')) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="<?= $adminShell && str_contains($bodyClass, 'admin-dark') ? '#0b0e14' : '#3978F6' ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= $adminApp ? 'مدیریت ' . esc($siteName) : esc($siteName) ?>">
<link rel="manifest" href="<?= $adminApp ? 'admin.webmanifest' : 'manifest.webmanifest' ?>">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/icons/<?= $adminApp ? 'admin-apple-touch-icon.png' : 'apple-touch-icon.png' ?>">
<link rel="preload" href="assets/fonts/regular.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="assets/fonts/bold.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="assets/style.css?v=<?= esc(APP_VERSION) ?>">
<?php if (str_contains($bodyClass, 'doctor-register-page')): ?><link rel="stylesheet" href="assets/doctor-register.css?v=<?= esc(APP_VERSION) ?>"><?php endif; ?>
<?php if ($adminShell): ?><link rel="stylesheet" href="assets/admin.css?v=<?= esc(APP_VERSION) ?>"><?php endif; ?>
<?php if ($jsonld): ?><script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
</head>
<body class="<?= esc($bodyClass) ?>">
<a class="skip-link" href="#main">رفتن به محتوای اصلی</a>
<?php if (schema_is_current_safe() && app_environment() === 'development'): ?>
<div class="env-banner" role="status">محیط توسعه — پیامک‌ها در حالت آزمایشی ممکن است ارسال واقعی نشوند.</div>
<?php endif; ?>
<?php if (!$adminShell): ?>
<?php if (!empty($GLOBALS['panel_navigation'])): $panel = $GLOBALS['panel_navigation']; ?>
<header class="site-header" data-sticky-header><div class="header-inner">
<button type="button" class="menu-toggle" aria-controls="side-drawer" aria-expanded="false" aria-label="باز کردن منوی پنل" data-drawer-open hidden><?= icon('menu') ?></button>
<a class="brand" href="<?= esc($panel['home']) ?>"><?= site_brand_logo() ?><span><?= esc($panel['title']) ?><small>نوبت‌یار</small></span></a>
<div class="header-actions"><a class="bell-link" href="<?= esc($panel['notifications']) ?>" aria-label="اعلان‌های پنل"><?= icon('bell') ?></a></div>
</div></header>
<?php else: ?>
<header class="site-header" data-sticky-header>
  <div class="header-inner">
    <?php if (!in_array($page, ['index.php', 'login.php', 'admin.php'], true) || isset($_GET['view']) || array_intersect_key($_GET, array_flip(['q', 'specialty', 'specialty_id', 'scope', 'sort', 'page']))): ?>
    <a class="header-back" href="index.php" data-history-back aria-label="بازگشت" title="بازگشت"><?= icon('chevron-right') ?></a>
    <?php endif; ?>
    <button type="button" class="menu-toggle" aria-controls="side-drawer" aria-expanded="false" aria-label="باز کردن منو" data-drawer-open hidden><?= icon('menu') ?></button>
    <a class="brand<?= site_logo_url() ? ' brand-logo-only' : '' ?>" href="index.php" aria-label="<?= esc($siteName) ?> — صفحه اصلی"><?= site_brand_logo() ?><?php if (!site_logo_url()): ?><span><?= esc($siteName) ?><small>سامانه نوبت‌دهی پزشکان</small></span><?php endif; ?></a>
    <nav class="site-nav" aria-label="منوی اصلی">
      <?php foreach ($nav as [$href, $label, $pages]): ?>
        <a href="<?= esc($href) ?>"<?= in_array($page, $pages, true) && !($href === 'index.php' && isset($_GET['view'])) ? ' class="current" aria-current="page"' : '' ?>><?= esc($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="header-actions">
      <a class="header-search" href="index.php?view=doctors#search" aria-label="جستجوی پزشک" title="جستجوی پزشک"><?= icon('search') ?></a>
      <a class="city-chip" href="city.php" title="تغییر استان و شهر">
        <?= icon('pin') ?><span><?= $city ? esc($city['name']) : 'انتخاب شهر' ?></span><?= icon('chevron-down', 'icon chev') ?>
      </a>
      <?php if ($user): foreach (array_slice(array_values(array_filter(account_workspaces($user), fn($w) => in_array($w['kind'] ?? '', ['doctor', 'secretary'], true))), 0, 2) as $w): /* rc.21 */ ?>
        <a class="button ghost header-doctor header-work" href="<?= esc($w['url']) ?>"><?= icon($w['icon']) ?><span><?= esc($w['title']) ?></span></a>
      <?php endforeach; endif; ?>
      <?php if ($user): ?>
        <a class="bell-link" href="notifications.php" aria-label="اعلان‌ها<?= $unread ? '، ' . fa_number($unread) . ' خوانده‌نشده' : '' ?>"><?= icon('bell') ?><?php if ($unread): ?><span class="bell-count" data-unread><?= fa_number(min($unread, 99)) ?></span><?php endif; ?></a>
        <a class="button header-account" href="panel.php"><?= icon('user') ?><span>حساب من</span></a>
      <?php else: ?>
        <a class="button ghost header-doctor" href="login.php?next=doctor-panel.php" data-auth-open="doctor-panel.php"><?= icon('doctor') ?><span>ورود پزشکان</span></a>
        <a class="button header-account" href="login.php" data-auth-open><?= icon('login') ?><span>ورود / ثبت‌نام</span></a>
      <?php endif; ?>
      <?php if ($isAdmin): ?><a class="header-admin" href="admin.php" title="مدیریت"><?= icon('layout') ?><span class="sr-only">مدیریت</span></a><?php endif; ?>
    </div>
  </div>
</header>
<?php endif; ?>
<?php endif; ?>
<main id="main">
