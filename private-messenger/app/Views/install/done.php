<?php use function PM\Support\e; $title = 'نصب کامل شد'; include __DIR__ . '/../_head.php'; ?>
<div class="page">
  <h1>نصب کامل شد</h1>
  <div class="alert warn"><b>این آدرس‌ها فقط همین یک بار نمایش داده می‌شوند.</b> آن‌ها را در مدیر گذرواژه ذخیره کنید. در صورت فراموشی، فقط از فایل <span class="ltr">config/.env</span> (مقادیر APP_PATH و ADMIN_PATH) قابل بازیابی‌اند.</div>
  <div class="panel">
    <h2>آدرس ورود کاربران</h2>
    <div class="secret">https://<?= e($host) ?>/<?= e($app_path) ?>/</div>
    <h2 class="mt">آدرس پنل مدیریت</h2>
    <div class="secret">https://<?= e($host) ?>/<?= e($admin_path) ?>/</div>
    <p class="small muted">این آدرس‌ها را هرگز در وب منتشر نکنید و فقط از کانال امن به کاربران بدهید. صفحه اصلی دامنه، <span class="ltr">/install</span> و هر آدرس دیگری اکنون 404 می‌دهد.</p>
  </div>
  <div class="panel">
    <h2>Cron (cPanel ← Cron Jobs)</h2>
    <div class="secret">*/15 * * * * /usr/local/bin/php <?= e($root) ?>/app/Cli/cron.php maintenance</div>
    <p class="small muted">مسیر php ممکن است در هاست شما متفاوت باشد؛ در صفحه «سیستم» پنل مدیریت هم نمایش داده می‌شود.</p>
  </div>
  <div class="panel small">
    <h2>گام‌های بعدی</h2>
    <ol>
      <li>وارد پنل مدیریت شوید و از «حساب من» ورود دومرحله‌ای (TOTP) را فعال کنید.</li>
      <li>کاربران را از بخش «کاربران» بسازید؛ رمز موقت هر کاربر یک بار نمایش داده می‌شود و کاربر در اولین ورود باید آن را تغییر دهد.</li>
      <li>روش ورود پیش‌فرض «نام کاربری و رمز عبور» است و در «تنظیمات» قابل تغییر به OTP است.</li>
    </ol>
  </div>
</div>
</body></html>
