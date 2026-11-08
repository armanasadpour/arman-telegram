<?php use function PM\Support\e; $title = $name; $nav = 'home'; include __DIR__ . '/../_head.php'; include __DIR__ . '/_nav.php'; ?>
<main class="page">
  <h1>سلام، <?= e($user['display_name']) ?></h1>
  <div class="panel">
    <p>ورود شما موفق بود.</p>
    <p class="small muted">این نسخه فاز ۲ است: ورود، مدیریت نشست‌ها و تغییر رمز عبور فعال است. پیام‌رسانی رمزنگاری‌شده سرتاسری در فازهای ۳ و ۴ اضافه می‌شود؛ تا آن زمان هیچ امکان پیام‌رسانی نمایش داده نمی‌شود.</p>
  </div>
</main>
</body></html>
