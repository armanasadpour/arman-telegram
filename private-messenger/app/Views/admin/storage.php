<?php use function PM\Support\e; use PM\Support\Fa; use PM\Admin\AdminService as A; $title = 'فضا و فایل‌ها'; include __DIR__ . '/_top.php';
$fmt = fn (?int $b) => $b === null ? 'نامعلوم' : ($b >= 1073741824 ? Fa::digits(number_format($b / 1073741824, 2)) . ' گیگابایت' : Fa::digits(number_format($b / 1048576, 1)) . ' مگابایت'); ?>
<h1>فضا و فایل‌ها</h1>
<div class="alert">سرور فقط نسخه رمزنگاری‌شده فایل‌ها را دارد و نمی‌تواند محتوای آن‌ها را ببیند یا باز کند.</div>
<?php if ($result): ?><div class="alert ok"><?= isset($result['busy']) ? 'پاک‌سازی هم‌اکنون در حال اجراست.' : e('پاک‌سازی انجام شد: ' . Fa::digits($result['files']['files_deleted'] ?? 0) . ' فایل منقضی و ' . Fa::digits($result['files']['stale_uploads_deleted'] ?? 0) . ' آپلود ناتمام حذف شد (' . $fmt($result['files']['bytes_freed'] ?? 0) . ' آزاد شد).') ?></div><?php endif; ?>
<?php if ($st['free'] !== null && $st['free'] < 1073741824): ?><div class="alert warn">فضای آزاد دیسک کمتر از ۱ گیگابایت است. آپلودهای جدید در صورت کمبود فضا با پیام روشن متوقف می‌شوند؛ هیچ فایلی بی‌اجازه حذف نمی‌شود.</div><?php endif; ?>
<div class="stats">
  <div class="stat"><span class="small muted">فایل‌های روی سرور</span><b><?= e(Fa::digits($st['files'])) ?></b><span class="small"><?= e($fmt($st['stored'])) ?></span></div>
  <div class="stat"><span class="small muted">نگهداری دائمی</span><b><?= e($fmt($st['permanent'])) ?></b></div>
  <div class="stat"><span class="small muted">منقضی، در انتظار حذف</span><b><?= e($fmt($st['expired_pending'])) ?></b><span class="small">از دسترس خارج شده‌اند</span></div>
  <div class="stat"><span class="small muted">آپلودهای ناتمام</span><b><?= e($fmt($st['partial'])) ?></b><span class="small">پس از ۴۸ ساعت حذف می‌شوند</span></div>
  <div class="stat"><span class="small muted">فضای آزاد پارتیشن</span><b><?= e($fmt($st['free'])) ?></b><span class="small">فضای پارتیشن، نه سهمیه حساب</span></div>
  <div class="stat"><span class="small muted">سهمیه هاست (ورودی مدیر)</span><b><?= e($st['quota'] ? $fmt($st['quota']) : 'نامعلوم') ?></b><?php if ($st['quota']): ?><span class="small">مصرف فایل‌ها: <?= e(Fa::digits(number_format(100 * $st['stored'] / $st['quota'], 1))) ?>٪</span><?php endif; ?></div>
</div>
<div class="panel small">
  <p>سقف حجم هر فایل: <b><?= $max ? e($fmt($max)) : 'بدون محدودیت نرم‌افزاری' ?></b> — اندازه هر قطعه آپلود روی این هاست: <?= e($fmt($chunk)) ?> (بر اساس post_max_size)</p>
  <p>آخرین پاک‌سازی: <?= $last ? e(Fa::dateTime($last['finished_at']) . ' (' . ['cron' => 'Cron', 'pseudo' => 'خودکار هنگام استفاده', 'manual' => 'دستی'][$last['trigger_source']] . ')') : 'هنوز اجرا نشده' ?></p>
  <p class="muted">فایل‌ها ۷ روز پس از آپلود حذف می‌شوند. فایل منقضی بلافاصله از دسترس خارج می‌شود، حتی اگر حذف فیزیکی هنوز اجرا نشده باشد. نسخه پشتیبان پوشه storage/attachments را حداکثر ۷ روز نگه دارید.</p>
  <?php if (A::can($admin, 'storage.cleanup.run')): ?>
  <form method="post" action="<?= e($base) ?>/storage/cleanup"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary">اجرای پاک‌سازی همین حالا</button></form>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
