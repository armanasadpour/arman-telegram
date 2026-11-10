<?php use function PM\Support\e; use PM\Admin\AdminService as A; include __DIR__ . '/../_head.php'; ?>
<header class="topbar">
  <nav>
    <?php foreach ([['dash', '/', 'داشبورد', 'dashboard.view'], ['users', '/users', 'کاربران', 'users.view'], ['settings', '/settings', 'تنظیمات', 'settings.view'], ['storage', '/storage', 'فضا و فایل‌ها', 'storage.view'], ['fonts', '/fonts', 'فونت', 'settings.view'],
                    ['admins', '/admins', 'مدیران', 'admins.manage'], ['reports', '/reports', 'گزارش‌ها', 'reports.view'], ['audit', '/audit', 'گزارش ممیزی', 'audit.view'], ['backup', '/backup', 'پشتیبان‌گیری', 'backup.download'],
                    ['system', '/system', 'سیستم', 'system.capabilities.view'], ['account', '/account', 'حساب من', null]] as [$k, $href, $label, $perm]):
        if ($perm === null || A::can($admin, $perm)): ?>
      <a href="<?= e($base . $href) ?>" class="<?= $nav === $k ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endif; endforeach; ?>
  </nav>
  <div class="row small"><span class="muted"><?= e($admin['username']) ?> · <?= e($admin['role_name']) ?></span>
    <form method="post" action="<?= e($base) ?>/logout" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary sm">خروج</button></form></div>
</header>
<main class="page">
<?php if (!empty($msg)): ?><div class="alert ok" role="status"><?= e($msg) ?></div><?php endif; ?>
