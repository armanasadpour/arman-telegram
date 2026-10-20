<?php
/** @var array $sections visible sections @var string $tab @var string $title @var string $error @var array $me @var string $theme @var array $badges @var string $headingAside */
$groups = [];
foreach ($sections as $key => $s) {
    if ($s['group'] !== '') {
        $groups[$s['group']][$key] = $s;
    }
}
$role = admin_roles()[$me['role']] ?? null;
$myName = (string) ($me['name'] ?: $me['email']);
$todayLong = (new IntlDateFormatter('fa_IR@calendar=persian', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'Asia/Tehran', IntlDateFormatter::TRADITIONAL, 'EEEE d MMMM yyyy'))->format(new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran')));
$otherTheme = $theme === 'dark' ? 'light' : 'dark';
$themeUrl = 'admin.php?' . http_build_query(['theme' => $otherTheme] + array_diff_key($_GET, ['theme' => 1, 'export' => 1, 'download' => 1]));
$supportCount = (int) ($badges['support'] ?? 0);
$subtitles = [
    'dashboard' => 'خوش آمدید، ' . $myName . '؛ وضعیت سامانه در یک نگاه.',
];
?>
<div class="ad-scene" aria-hidden="true"></div>
<div class="admin-shell">
  <aside class="admin-sidebar" id="admin-menu" aria-label="بخش‌های مدیریت" tabindex="-1">
    <div class="ad-brand">
      <a href="admin.php" class="ad-brand-link">
        <span class="ad-logo"><?= site_brand_logo('admin-brand-logo') ?></span>
        <span class="ad-brand-text"><strong><?= esc(site_name()) ?></strong><small>مرکز مدیریت</small></span>
      </a>
      <a class="ad-close" href="#" data-menu-close aria-label="بستن منو"><?= icon('x') ?></a>
    </div>
    <nav class="admin-nav" aria-label="منوی مدیریت">
      <?php foreach ($groups as $group => $items): ?>
        <span class="nav-group"><?= esc($group) ?></span>
        <?php foreach ($items as $key => $s): ?>
          <a href="admin.php?tab=<?= esc($key) ?>" data-label="<?= esc($s['label'] . ' ' . $group) ?>"<?= $key === $tab ? ' class="current" aria-current="page"' : '' ?>>
            <?= icon($s['icon']) ?><span><?= esc($s['label']) ?></span>
            <?php if (!empty($badges[$key])): ?><b class="nav-count" aria-label="<?= esc(fa_number($badges[$key])) ?> مورد در انتظار"><?= esc(fa_number(min(99, $badges[$key]))) ?></b><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>
    <div class="ad-help">
      <strong>نسخه <?= esc(APP_VERSION_FA) ?></strong>
      <p>تغییرات مهم در گزارش فعالیت ثبت می‌شوند.</p>
      <a class="ad-help-link" href="index.php" target="_blank" rel="noopener"><?= icon('external') ?> مشاهده سایت</a>
    </div>
    <div class="admin-sidebar-head ad-me-row">
      <a class="admin-me<?= $tab === 'account' ? ' current' : '' ?>" href="admin.php?tab=account">
        <span class="ad-avatar" aria-hidden="true"><?= esc(name_initials($myName)) ?></span>
        <span><strong><?= esc($myName) ?></strong><small><?= esc($role['label'] ?? '') ?></small></span>
      </a>
      <form method="post" class="inline-form">
        <?php form_token('logout'); ?>
        <button class="link-button ad-logout" title="خروج" aria-label="خروج"><?= icon('logout') ?><span>خروج</span></button>
      </form>
    </div>
  </aside>
  <a class="ad-overlay" href="#" data-menu-close tabindex="-1" aria-hidden="true"></a>
  <section class="admin-content" id="admin-main">
    <header class="ad-topbar">
      <a class="ad-icon-btn ad-menu-btn" href="#admin-menu" data-menu-open aria-controls="admin-menu" aria-expanded="false" aria-label="باز کردن منو"><?= icon('menu') ?></a>
      <div class="ad-finder" data-finder hidden>
        <?= icon('search') ?>
        <input type="search" placeholder="جستجوی بخش‌ها…" aria-label="جستجوی بخش‌های مدیریت" autocomplete="off" data-finder-input>
        <kbd aria-hidden="true">Ctrl K</kbd>
        <ul class="ad-finder-list" role="listbox" data-finder-list hidden></ul>
      </div>
      <div class="ad-top-actions">
        <a class="ad-icon-btn" href="<?= esc($themeUrl) ?>" title="<?= $theme === 'dark' ? 'حالت روشن' : 'حالت تیره' ?>" aria-label="<?= $theme === 'dark' ? 'تغییر به حالت روشن' : 'تغییر به حالت تیره' ?>"><?= icon($theme === 'dark' ? 'sparkles' : 'eye') ?></a>
        <?php if (isset($sections['support'])): ?>
          <a class="ad-icon-btn ad-bell" href="admin.php?tab=support" aria-label="تیکت‌های پشتیبانی<?= $supportCount ? '، ' . esc(fa_number($supportCount)) . ' باز' : '' ?>" title="تیکت‌های پشتیبانی"><?= icon('bell') ?><?php if ($supportCount): ?><i class="ad-dot" aria-hidden="true"></i><?php endif; ?></a>
        <?php endif; ?>
        <a class="ad-site-btn" href="index.php" target="_blank" rel="noopener"><?= icon('home') ?><span>سایت</span></a>
      </div>
    </header>
    <div class="admin-heading">
      <div class="ad-heading-text">
        <p class="eyebrow">مدیریت <?= esc(site_name()) ?> <span aria-hidden="true">•</span> <?= esc($todayLong) ?></p>
        <h1><?= esc($title) ?></h1>
        <?php if (isset($subtitles[$tab])): ?><p class="ad-subtitle"><?= esc($subtitles[$tab]) ?></p><?php endif; ?>
      </div>
      <?php if ($headingAside !== ''): ?><div class="ad-heading-aside"><?= $headingAside ?></div><?php endif; ?>
    </div>
    <?php if (isset($_SESSION['flash'])): ?><p class="notice" role="status"><?= esc($_SESSION['flash']) ?></p><?php unset($_SESSION['flash']); endif; ?>
    <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>
