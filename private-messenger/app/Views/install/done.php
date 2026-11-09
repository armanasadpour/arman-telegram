<?php use function PM\Support\e; $title = 'نصب کامل شد'; include __DIR__ . '/../_head.php';
$appUrl = 'https://' . $host . '/' . $app_path . '/'; $admUrl = 'https://' . $host . '/' . $admin_path . '/'; ?>
<div class="page">
  <h1>✔ نصب کامل شد</h1>
  <div class="alert warn"><b>مهم: این دو آدرس را همین حالا ذخیره کنید</b> (مثلاً در یادداشت گوشی یا مدیر رمز). این صفحه دوباره نمایش داده نمی‌شود و صفحه اصلی سایت از این به بعد «پیدا نشد» نشان می‌دهد.</div>
  <div class="panel">
    <h2>آدرس پنل مدیریت (برای خودتان)</h2>
    <div class="secret"><a href="<?= e($admUrl) ?>"><?= e($admUrl) ?></a></div>
    <h2 class="mt">آدرس ورود کاربران (برای اعضا)</h2>
    <div class="secret"><?= e($appUrl) ?></div>
    <p class="small muted">اگر روزی آدرس‌ها را گم کردید: در File Manager فایل <span class="ltr"><?= e($root) ?>/config/.env</span> را باز کنید؛ مقدارهای APP_PATH و ADMIN_PATH همان بخش آخر آدرس‌ها هستند.</p>
  </div>
  <div class="panel small">
    <h2>کارهای بعدی</h2>
    <ol>
      <li>روی آدرس پنل مدیریت بزنید و با نام کاربری و رمزی که انتخاب کردید وارد شوید.</li>
      <li>در «حساب من»، ورود دومرحله‌ای (Google Authenticator) را فعال کنید.</li>
      <li>در «کاربران»، برای اعضا حساب بسازید و رمز موقت هر نفر را به خودش بدهید.</li>
    </ol>
    <p class="muted"><?= $cron === 'auto'
        ? 'Cron به‌صورت خودکار در cPanel ثبت شد.'
        : 'کارهای نگهداری به‌صورت خودکار هنگام استفاده از برنامه اجرا می‌شوند و نیازی به تنظیم Cron نیست. (اختیاری برای دقت بیشتر: افزودن این فرمان در Cron Jobs هر ۱۵ دقیقه)' ?></p>
    <?php if ($cron !== 'auto'): ?><div class="secret small"><?= e($cron_cmd) ?></div><?php endif; ?>
  </div>
</div>
</body></html>
