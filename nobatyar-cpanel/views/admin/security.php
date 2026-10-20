<?php
/** @var array $checks @var array $backups @var bool $canBackup @var bool $auto @var string $keep @var array $securityLog @var array $errorLog @var array $events */
$state = ['ok' => ['check', 'درست'], 'warn' => ['alert', 'نیاز به توجه'], 'fail' => ['x', 'مشکل']];
$size = fn(int $b) => $b >= 1048576 ? fa_number(number_format($b / 1048576, 1)) . ' مگابایت' : fa_number(max(1, (int) round($b / 1024))) . ' کیلوبایت';
?>
<section class="panel">
  <div class="panel-head"><h2>بررسی امنیتی هاست</h2><span class="muted small">فقط خواندنی؛ هیچ مقدار محرمانه‌ای نمایش داده نمی‌شود.</span></div>
  <ul class="checklist">
    <?php foreach ($checks as [$label, $status, $detail]): ?>
      <li class="<?= $status === 'ok' ? 'ok' : ($status === 'fail' ? 'fail' : 'warn') ?>"><?= icon($state[$status][0]) ?><span><strong><?= esc($label) ?> <small class="sr-only"><?= esc($state[$status][1]) ?></small></strong><small><?= esc($detail) ?></small></span></li>
    <?php endforeach; ?>
  </ul>
</section>

<?php if ($canBackup): ?>
<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>پشتیبان دیتابیس</h2>
      <form method="post" class="inline-form"><?php form_token('backup_create'); ?><button><?= icon('download') ?> ساخت پشتیبان اکنون</button></form>
    </div>
    <p class="muted small">پشتیبان شامل همه جدول‌هاست و در پوشه private/backups (بسته به روی وب) نگه داشته می‌شود. تصاویر بارگذاری‌شده (پوشه private/media و private/photos) و فایل private/app.key جزو آن نیستند؛ آن‌ها را با File Manager جداگانه کپی کنید. بدون app.key کلیدهای ذخیره‌شده سرویس‌ها قابل خواندن نیست.</p>
    <?php if (!$backups): ?>
      <p class="empty-state">هنوز پشتیبانی ساخته نشده است.</p>
    <?php else: ?>
      <div class="table-wrap"><table>
        <thead><tr><th>فایل</th><th>زمان</th><th>نوع</th><th>حجم</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td><small dir="ltr"><?= esc($b['name']) ?></small><?php if (!$b['header']): ?><br><span class="badge status-cancelled">نامعتبر</span><?php elseif ($b['header']['schema'] !== SCHEMA_VERSION): ?><br><span class="badge status-held">نسخه دیگر (مرحله <?= fa_number($b['header']['schema']) ?>)</span><?php endif; ?></td>
            <td><bdi><?= esc(jdate(date('Y-m-d H:i:s', $b['time']), true)) ?></bdi></td>
            <td><?= esc(['manual' => 'دستی', 'auto' => 'خودکار', 'prerestore' => 'پیش از بازیابی', 'preupdate' => 'پیش از به‌روزرسانی', 'uploaded' => 'بارگذاری‌شده'][$b['kind']] ?? $b['kind']) ?></td>
            <td><?= $size($b['size']) ?></td>
            <td class="row-actions">
              <a href="admin.php?tab=security&amp;download=<?= esc(rawurlencode($b['name'])) ?>">دریافت</a>
              <form method="post" class="inline-form" data-confirm="این فایل پشتیبان حذف شود؟"><?php form_token('backup_delete'); ?><input type="hidden" name="name" value="<?= esc($b['name']) ?>"><button class="danger small">حذف</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
    <form method="post" class="stack">
      <?php form_token('backup_settings'); ?>
      <label class="check"><input type="checkbox" name="backup_auto" value="1"<?= checked_attr($auto) ?>> پشتیبان خودکار روزانه با Cron</label>
      <?php input('تعداد پشتیبان‌های نگه‌داشته‌شده', 'backup_keep', $keep, 'number', true, 2); ?>
      <button class="secondary">ذخیره تنظیمات پشتیبان</button>
    </form>
  </section>
  <section class="panel">
    <h2>بازیابی</h2>
    <p class="muted small">بازیابی، کل اطلاعات فعلی را با محتوای فایل جایگزین می‌کند. پیش از آن، خودکار یک پشتیبان «پیش از بازیابی» ساخته می‌شود. فقط پشتیبان همین نسخه دیتابیس پذیرفته می‌شود. پس از بازیابی همه کاربران و مدیران باید دوباره وارد شوند.</p>
    <form method="post" enctype="multipart/form-data" class="stack">
      <?php form_token('backup_upload'); ?>
      <label>بارگذاری فایل پشتیبان (‎.sql.gz‎)<input type="file" name="backup" accept=".gz,application/gzip" required></label>
      <button class="secondary"><?= icon('plus') ?> بارگذاری</button>
    </form>
    <?php $restorable = array_filter($backups, fn($b) => $b['header'] && $b['header']['schema'] === SCHEMA_VERSION); ?>
    <?php if ($restorable): ?>
      <form method="post" class="stack danger-zone" autocomplete="off" data-confirm="همه اطلاعات فعلی با این پشتیبان جایگزین شود؟">
        <?php form_token('backup_restore'); ?>
        <label>فایل<select name="name" required><?php foreach ($restorable as $b): ?><option value="<?= esc($b['name']) ?>"><?= esc(jdate(date('Y-m-d H:i:s', $b['time']), true)) ?> — <?= esc($b['name']) ?></option><?php endforeach; ?></select></label>
        <?php input('رمز عبور شما (مالک سامانه)', 'password', '', 'password', true, 255); ?>
        <?php input('برای تأیید بنویسید: بازیابی', 'confirm', '', 'text', true, 20); ?>
        <button class="danger"><?= icon('refresh') ?> بازیابی دیتابیس</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php endif; ?>

<div class="grid-2 align-start">
  <section class="panel">
    <div class="panel-head"><h2>رویدادهای امنیتی اخیر</h2><a href="admin.php?tab=audit&amp;actor=">گزارش کامل</a></div>
    <?php if (!$events): ?><p class="empty-state">رویداد امنیتی ثبت نشده است.</p><?php else: ?>
      <ul class="list">
        <?php foreach ($events as $e): ?><li><?= esc(audit_label($e['action'])) ?> <small><bdi><?= esc(jdate($e['created_at'], true)) ?></bdi><?= $e['detail'] ? ' • ' . esc(admin_sensitive() ? $e['detail'] : '…') : '' ?><?= admin_sensitive() && $e['ip'] ? ' • <span dir="ltr">' . esc($e['ip']) . '</span>' : '' ?></small></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>خطاهای اخیر برنامه</h2><span class="muted small">private/logs</span></div>
    <?php if (!$errorLog): ?><p class="empty-state">خطایی ثبت نشده است.</p><?php else: ?>
      <ul class="list log-list">
        <?php foreach ($errorLog as $l): ?><li><code dir="ltr"><?= esc($l['ref'] ?? '') ?></code> <?= esc(mb_substr((string) ($l['message'] ?? ''), 0, 160)) ?> <small><bdi><?= esc(jdate(date('Y-m-d H:i:s', strtotime((string) $l['at'])), true)) ?></bdi> • <span dir="ltr"><?= esc((string) ($l['uri'] ?? '')) ?></span></small></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
