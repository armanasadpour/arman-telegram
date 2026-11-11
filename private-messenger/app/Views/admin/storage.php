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
  <p class="muted">فایل‌ها <?= e(Fa::digits($retention)) ?> روز پس از آپلود حذف می‌شوند. فایل منقضی بلافاصله از دسترس خارج می‌شود، حتی اگر حذف فیزیکی هنوز اجرا نشده باشد. نسخه پشتیبان پوشه storage/attachments را حداکثر <?= e(Fa::digits($retention)) ?> روز نگه دارید.</p>
  <?php if (A::can($admin, 'storage.cleanup.run')): ?>
  <form method="post" action="<?= e($base) ?>/storage/cleanup"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary">اجرای پاک‌سازی همین حالا</button></form>
  <?php endif; ?>
</div>
<div class="panel table-wrap">
  <h2>بیشترین مصرف فضا (فایل‌های فعلی روی سرور)</h2>
  <p class="small muted">سهمیه پیش‌فرض هر کاربر: <b><?= $default_quota ? e($fmt($default_quota)) : 'نامحدود' ?></b> (تنظیمات ← فایل‌ها و فضا). سهمیه اختصاصی را در صفحه هر کاربر تعیین کنید.</p>
  <table><tr><th>کاربر</th><th>فایل</th><th>مصرف</th><th>سهمیه</th></tr>
  <?php foreach ($top as $t): $tq = $t['storage_quota_bytes'] !== null ? (int)$t['storage_quota_bytes'] : $default_quota; ?>
    <tr><td><a href="<?= e($base) ?>/users/<?= (int)$t['id'] ?>"><?= e($t['display_name']) ?></a> <span class="ltr small muted">@<?= e($t['username']) ?></span></td>
      <td><?= e(Fa::digits((int)$t['files'])) ?></td><td><?= e($fmt((int)$t['used'])) ?></td>
      <td><?= $tq ? e($fmt($tq)) . ' (' . e(Fa::digits(min(999, (int)round(100 * $t['used'] / $tq)))) . '٪)' : 'نامحدود' ?><?= $t['storage_quota_bytes'] !== null ? ' <span class="tag">اختصاصی</span>' : '' ?></td></tr>
  <?php endforeach; if (!$top): ?><tr><td colspan="4" class="muted">هنوز فایلی روی سرور نیست.</td></tr><?php endif; ?></table>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
