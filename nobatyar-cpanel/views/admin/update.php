<?php
/**
 * One-click update (owner only): upload the release ZIP → automatic checks → preview → install.
 * @var array $checks @var bool $ready @var int $limit @var ?array $pending @var ?array $plan @var array $rollbacks @var array $history
 */
$notes = function (string $text): string {
    $out = '';
    foreach (preg_split('/\R/u', $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $line = preg_replace('/\*\*|`/u', '', $line);
        $out .= str_starts_with($line, '- ') ? '<li>' . esc(mb_substr($line, 2)) . '</li>' : '<li class="un-head">' . esc(ltrim($line, '# ')) . '</li>';
    }
    return '<ul class="update-notes">' . $out . '</ul>';
};
$labels = ['update.apply' => 'نصب', 'update.finish' => 'تکمیل دیتابیس', 'update.rollback' => 'بازگرداندن', 'update.failed' => 'ناموفق (برگردانده شد)', 'update.rejected' => 'بسته رد شد'];
?>
<section class="panel update-head">
  <span class="uh-icon"><?= icon('download') ?></span>
  <div>
    <h2>به‌روزرسانی سامانه</h2>
    <p class="muted">نسخه نصب‌شده: <b><?= esc(APP_VERSION_FA) ?></b> <bdi dir="ltr" class="muted">(<?= esc(APP_VERSION) ?>)</bdi> • مرحله دیتابیس <?= fa_number(schema_version(db())) ?></p>
  </div>
</section>

<ol class="update-steps" aria-label="مراحل">
  <li class="<?= $pending ? 'done' : 'current' ?>"><span>۱</span>بارگذاری فایل ZIP</li>
  <li class="<?= $pending ? 'done' : '' ?>"><span>۲</span>بررسی خودکار بسته</li>
  <li class="<?= $pending ? 'current' : '' ?>"><span>۳</span>نصب با یک کلیک</li>
</ol>

<?php if (!$pending): ?>
  <section class="panel">
    <h2>بارگذاری نسخه جدید</h2>
    <?php if (!$ready): ?>
      <p class="error" role="alert">پیش‌نیازهای هاست کامل نیست (موارد قرمز پایین صفحه). تا رفع آن‌ها از روش دستی استفاده کنید.</p>
    <?php else: ?>
      <form method="post" enctype="multipart/form-data" class="update-upload">
        <?php form_token('update_upload'); ?>
        <label class="drop-zone">
          <?= icon('download') ?>
          <strong>فایل ZIP نسخه جدید را انتخاب کنید</strong>
          <small>همان فایلی که دریافت کرده‌اید (مثلاً nobatyar-cpanel-1.0.0.zip)، بدون باز کردن یا تغییر نام پوشه داخل آن • حداکثر <?= esc(update_size_label($limit)) ?></small>
          <input type="file" name="package" accept=".zip,application/zip" required>
        </label>
        <button><?= icon('check') ?> بارگذاری و بررسی بسته</button>
      </form>
      <p class="muted small">پیش از نصب، بسته کامل بررسی می‌شود: سالم بودن همه فایل‌ها، جدیدتر بودن نسخه و سازگاری با دیتابیس. تا زمانی که «نصب به‌روزرسانی» را نزنید هیچ فایلی از سایت تغییر نمی‌کند.</p>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="panel update-preview">
    <div class="panel-head"><h2>بسته آماده نصب است</h2><span class="badge status-confirmed"><?= icon('check') ?> سالم</span></div>
    <div class="version-jump">
      <div><small>نسخه فعلی</small><b><?= esc(APP_VERSION_FA) ?></b></div>
      <span aria-hidden="true"><?= icon('chevron-left') ?></span>
      <div class="to"><small>نسخه جدید</small><b><?= esc($pending['version_fa']) ?></b></div>
    </div>
    <?php if ($pending['version'] === APP_VERSION): ?><p class="notice">این همان نسخه نصب‌شده است؛ نصب دوباره فقط فایل‌های تغییرکرده یا حذف‌شده را بازمی‌گرداند.</p><?php endif; ?>
    <dl class="update-facts">
      <div><dt>فایل‌های تغییرکرده</dt><dd><?= fa_number(count($plan['changed'])) ?></dd></div>
      <div><dt>فایل‌های جدید</dt><dd><?= fa_number(count($plan['new'])) ?></dd></div>
      <div><dt>بدون تغییر</dt><dd><?= fa_number($plan['same']) ?></dd></div>
      <div><dt>دیتابیس</dt><dd><?= $pending['schema'] > schema_version(db()) ? 'مرحله ' . fa_number(schema_version(db())) . ' ← ' . fa_number($pending['schema']) . ' (افزایشی)' : 'بدون تغییر' ?></dd></div>
    </dl>
    <?php if ($plan['changed'] || $plan['new']): ?>
      <details class="file-list"><summary>فهرست فایل‌ها</summary>
        <ul><?php foreach ($plan['new'] as $f): ?><li><span class="badge status-confirmed">جدید</span> <bdi dir="ltr"><?= esc($f) ?></bdi></li><?php endforeach; ?><?php foreach ($plan['changed'] as $f): ?><li><span class="badge">تغییر</span> <bdi dir="ltr"><?= esc($f) ?></bdi></li><?php endforeach; ?></ul>
      </details>
    <?php endif; ?>
    <?php if ($pending['notes'] !== ''): ?>
      <details class="file-list" open><summary>تغییرات این نسخه</summary><?= $notes($pending['notes']) ?></details>
    <?php endif; ?>
    <ul class="update-safe">
      <li><?= icon('check') ?> پیش از نصب، پشتیبان کامل دیتابیس و نسخه قبلی همه فایل‌های تغییرکرده خودکار ذخیره می‌شود.</li>
      <li><?= icon('check') ?> config.php، پوشه private (تصاویر، کلیدها، پشتیبان‌ها) و تنظیمات PHP هاست در .htaccess دست نمی‌خورد.</li>
      <li><?= icon('check') ?> در چند ثانیه نصب، بازدیدکنندگان پیام «در حال به‌روزرسانی» می‌بینند؛ اطلاعات و نوبت‌ها حذف نمی‌شود.</li>
      <li><?= icon('check') ?> اگر نوشتن فایلی خطا بدهد، فایل‌های قبلی خودکار برگردانده می‌شود.</li>
    </ul>
    <form method="post" class="stack narrow" autocomplete="off" data-confirm="نسخه <?= esc($pending['version_fa']) ?> نصب شود؟">
      <?php form_token('update_apply'); ?>
      <?php input('رمز عبور شما (مالک سامانه)', 'password', '', 'password', true, 255); ?>
      <button><?= icon('download') ?> نصب به‌روزرسانی</button>
    </form>
    <form method="post" class="inline-form"><?php form_token('update_cancel'); ?><button class="link-button danger-link"><?= icon('x') ?> انصراف و حذف این بسته</button></form>
  </section>
<?php endif; ?>

<div class="grid-2 align-start">
  <section class="panel">
    <h2>پیش‌نیازهای هاست</h2>
    <ul class="checklist">
      <?php foreach ($checks as $c): ?>
        <li class="<?= $c['ok'] ? 'ok' : 'fail' ?>"><?= icon($c['ok'] ? 'check' : 'x') ?><span><strong><?= esc($c['label']) ?></strong></span></li>
      <?php endforeach; ?>
    </ul>
    <details class="file-list"><summary>روش دستی (اگر بارگذاری از پنل ممکن نبود)</summary>
      <ol class="how-list">
        <li>در File Manager سی‌پنل فایل ZIP را در پوشه سایت بارگذاری و Extract کنید.</li>
        <li>محتوای پوشه nobatyar-cpanel را روی فایل‌های فعلی کپی کنید (config.php و پوشه private را جایگزین نکنید).</li>
        <li>نشانی upgrade.php را باز کنید و ارتقای دیتابیس را اجرا کنید.</li>
      </ol>
    </details>
  </section>

  <section class="panel">
    <h2>بازگرداندن نسخه قبلی</h2>
    <?php if (!$rollbacks): ?>
      <p class="muted">پس از اولین به‌روزرسانی از همین صفحه، نسخه قبلی فایل‌ها اینجا قابل بازگرداندن است.</p>
    <?php else: $r = $rollbacks[0]; ?>
      <p>آخرین به‌روزرسانی: <bdi dir="ltr"><?= esc($r['from']) ?> → <?= esc($r['to']) ?></bdi> در <?= esc(jdate($r['at'], true)) ?>.</p>
      <p class="muted small">فایل‌های برنامه به نسخه <?= esc($r['from']) ?> برمی‌گردد و فایل‌های تازه آن نسخه حذف می‌شود. دیتابیس تغییر نمی‌کند (تغییرات دیتابیس همیشه افزایشی است)؛ پشتیبان دیتابیس پیش از آن به‌روزرسانی: <bdi><?= esc($r['backup']) ?></bdi>.</p>
      <form method="post" class="stack" autocomplete="off" data-confirm="فایل‌های نسخه <?= esc($r['from']) ?> برگردانده شود؟">
        <?php form_token('update_rollback'); ?>
        <?php input('رمز عبور شما (مالک سامانه)', 'password', '', 'password', true, 255); ?>
        <button class="secondary"><?= icon('refresh') ?> بازگرداندن نسخه قبلی</button>
      </form>
    <?php endif; ?>
  </section>
</div>

<?php if ($history): ?>
  <section class="panel">
    <h2>سابقه به‌روزرسانی</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>زمان</th><th>رویداد</th><th>جزئیات</th><th>مدیر</th></tr></thead>
      <tbody><?php foreach ($history as $h): ?><tr><td><?= esc(jdate($h['created_at'], true)) ?></td><td><?= esc($labels[$h['action']] ?? $h['action']) ?></td><td><bdi><?= esc((string) $h['detail']) ?></bdi></td><td><?= esc((string) $h['email']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
  </section>
<?php endif; ?>
