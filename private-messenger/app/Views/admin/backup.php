<?php use function PM\Support\e; use PM\Support\Fa; $title = 'پشتیبان‌گیری'; include __DIR__ . '/_top.php'; ?>
<h1>پشتیبان‌گیری</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="panel">
  <h2>نسخه پشتیبان دیتابیس</h2>
  <p>آخرین نسخه: <b><?= $last === '' ? 'هرگز' : e(Fa::dateTime($last)) ?></b> · حجم فعلی دیتابیس: <?= e(Fa::bytes($db_size)) ?></p>
  <p class="small">دیتابیس فقط متن رمزنگاری‌شده، کلیدهای عمومی و فراداده دارد؛ پس فایل پشتیبان هم پیام‌ها را فاش نمی‌کند. ولی شامل هش رمزهای عبور و تنظیمات است: آن را مثل خود سرور محافظت کنید و در جای امن نگه دارید.</p>
  <form method="post" action="<?= e($base) ?>/backup/download" class="row">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <input type="password" name="password" class="ltr grow" placeholder="رمز عبور حساب شما" autocomplete="current-password" required>
    <button class="btn">⬇ دریافت فایل <?= $gzip ? '.sql.gz' : '.sql' ?></button>
  </form>
  <p class="small muted">بازگردانی: یک دیتابیس خالی بسازید و فایل را در phpMyAdmin ← Import وارد کنید. فایل config/.env (کلید APP_KEY) را هم جداگانه و امن نگه دارید؛ بدون آن تنظیمات رمزشده (کلید پیامک، TURN) و TOTP مدیران بازیابی نمی‌شود.</p>
</div>
<div class="panel">
  <h2>یادآوری</h2>
  <form method="post" action="<?= e($base) ?>/backup/settings" class="row">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field grow"><span>اگر این تعداد روز از آخرین پشتیبان گذشت، در داشبورد هشدار بده (۰ = خاموش)</span><input type="number" name="reminder_days" min="0" max="365" value="<?= e($remind) ?>"></label>
    <button class="btn secondary">ذخیره</button>
  </form>
</div>
<div class="panel small">
  <h2>سیاست پشتیبان‌گیری از فایل‌ها</h2>
  <ul>
    <li>فایل‌های پیوست (پوشه <span class="ltr"><?= e($root) ?>/storage/attachments</span>) در این فایل نیستند؛ آن‌ها رمزنگاری‌شده‌اند و پس از <?= e(Fa::digits($retention)) ?> روز حذف می‌شوند.</li>
    <li>اگر از «Backup» خود cPanel استفاده می‌کنید، نسخه‌های قدیمی‌تر از <?= e(Fa::digits($retention)) ?> روز را پاک کنید تا فایل‌های حذف‌شده از راه بکاپ دوباره در دسترس قرار نگیرند.</li>
    <li>این برنامه هیچ‌گاه فایل حذف‌شده را از بکاپ برنمی‌گرداند؛ بازگردانی دیتابیس قدیمی هم فایل‌های حذف‌شده را برنمی‌گرداند و آن‌ها «منقضی» نمایش داده می‌شوند.</li>
    <li>پیام‌ها روی دستگاه کاربران ذخیره‌اند و در هیچ بکاپ سروری وجود ندارند.</li>
  </ul>
</div>
<?php if ($history): ?><div class="panel small"><h2>دریافت‌های اخیر</h2><?php foreach ($history as $h): ?><div><?= e(Fa::dateTime($h['created_at'])) ?> — <span class="ltr"><?= e((string)$h['username']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
