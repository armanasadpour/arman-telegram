<?php use function PM\Support\e; use PM\Support\Fa; use PM\Settings\Settings; $title = 'داشبورد'; include __DIR__ . '/_top.php'; ?>
<h1>داشبورد</h1>
<div class="alert">این پنل هیچ امکانی برای خواندن پیام‌ها یا فایل‌های کاربران ندارد. آمارها فقط از اطلاعات غیرمحتوایی محاسبه می‌شوند.</div>
<?php foreach ($warnings as $w): ?><div class="alert warn"><?= e($w) ?></div><?php endforeach; ?>
<div class="stats">
  <div class="stat"><span class="small muted">کاربران فعال</span><b><?= e(Fa::digits($stats['users']['active'] ?? 0)) ?></b></div>
  <div class="stat"><span class="small muted">در انتظار فعال‌سازی</span><b><?= e(Fa::digits($stats['users']['pending'] ?? 0)) ?></b></div>
  <div class="stat"><span class="small muted">معلق / مسدود</span><b><?= e(Fa::digits(($stats['users']['suspended'] ?? 0) + ($stats['users']['blocked'] ?? 0))) ?></b></div>
  <div class="stat"><span class="small muted">آنلاین (تقریبی، ۱۰ دقیقه)</span><b><?= e(Fa::digits($stats['online'])) ?></b></div>
  <div class="stat"><span class="small muted">نشست‌های فعال</span><b><?= e(Fa::digits($stats['sessions'])) ?></b></div>
  <div class="stat"><span class="small muted">حساب‌های قفل‌شده موقت</span><b><?= e(Fa::digits($stats['locked'])) ?></b></div>
</div>
<div class="panel small">
  <div>روش ورود فعلی: <b><?= e(Settings::AUTH_METHODS[$method] ?? $method) ?></b></div>
  <div>آخرین اجرای Cron نگهداری: <?= e($cron ? Fa::dateTime($cron['finished_at']) . ' (' . $cron['status'] . ')' : 'هرگز') ?></div>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
