<?php /** @var array $pushStats @var int $devices @var array $pushFailures @var array $waitlist @var int $reminders7 @var array $templates */
$ready = fn(string $e) => sms_template_ready($templates[$e]??null);
?>
<div class="stat-grid">
  <article class="stat-card"><span>دستگاه‌های دارای اعلان مرورگر</span><strong><?= fa_number($devices) ?></strong></article>
  <article class="stat-card"><span>اعلان مرورگر ارسال‌شده (۷ روز)</span><strong><?= fa_number($pushStats['sent'] ?? 0) ?></strong></article>
  <article class="stat-card"><span>پیامک یادآوری (۷ روز)</span><strong><?= fa_number($reminders7) ?></strong></article>
  <article class="stat-card"><span>درخواست‌های فعال صف انتظار</span><strong><?= fa_number($waitlist['waiting'] ?? 0) ?></strong></article>
</div>
<div class="grid-2 align-start">
  <section class="panel">
    <h2>یادآوری و صف انتظار</h2>
    <form method="post" class="stack">
      <?php form_token('notify_settings'); ?>
      <h3>یادآور اختیاری بیمار هنگام رزرو</h3>
      <?php input('چند ساعت قبل از نوبت (۱ تا ۱۶۸)', 'patient_reminder_hours', booking_reminder_hours(), 'number'); ?>
      <?php input('مبلغ یادآور (تومان؛ صفر برای رایگان)', 'patient_reminder_fee', booking_reminder_fee(), 'number'); ?>
      <p><a href="admin.php?tab=sms_templates">متن و فعال‌سازی پیامک یادآور در «الگوهای پیامک»</a></p>
      <p class="muted">مستقل از پلن پزشک؛ انتخاب بیمار پیش‌فرض روشن است. در رزروهای نزدیک‌تر از زمان یادآوری و هنگام آماده نبودن پیامک، این خدمت فروخته نمی‌شود. تغییر مبلغ و ساعت فقط روی رزروهای جدید اثر دارد.</p>
      <?php input('زمان‌های یادآوری پیش از نوبت (ساعت، با ویرگول؛ مثلاً ۲۴,۲)', 'reminder_hours', fa_number(setting('reminder_hours', '24,2')), 'text', false, 30); ?>
      <label class="check"><input type="checkbox" name="reminder_sms_enabled" value="1"<?= checked_attr(setting('reminder_sms_enabled', '1') === '1') ?>> یادآور پیامکی در مراحل رزرو قابل انتخاب باشد</label>
      <p class="muted small">الگوی «یادآوری پیش از نوبت»: <?= $ready('booking_reminder') ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">تنظیم نشده — پیامک ارسال نمی‌شود</span>' ?> • پیامک خریداری‌شده فقط یک بار در ساعت ثبت‌شده همراه رزرو ارسال می‌شود. زمان‌های زیر مربوط به اعلان رایگان داخل برنامه هستند.</p>
      <label class="check"><input type="checkbox" name="waitlist_enabled" value="1"<?= checked_attr(setting('waitlist_enabled', '1') === '1') ?>> صف انتظار فعال باشد</label>
      <div class="form-grid">
        <?php input('حداکثر طول بازه صف (روز)', 'waitlist_max_days', setting('waitlist_max_days', '14'), 'number', true, 2); ?>
        <?php input('فاصله اطلاع‌رسانی دوباره به هر نفر (دقیقه)', 'waitlist_cooldown_minutes', setting('waitlist_cooldown_minutes', '120'), 'number', true, 4); ?>
        <?php input('تعداد نفرات مطلع‌شده در هر نوبت بررسی', 'waitlist_batch', setting('waitlist_batch', '3'), 'number', true, 2); ?>
        <?php input('سقف درخواست فعال هر بیمار', 'waitlist_max_entries', setting('waitlist_max_entries', '5'), 'number', true, 2); ?>
      </div>
      <p class="muted small">الگوی «زمان آزاد برای صف انتظار»: <?= $ready('waitlist_available') ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">تنظیم نشده</span>' ?> • «تغییر زمان تقریبی مراجعه»: <?= $ready('visit_time_changed') ? '<span class="badge status-confirmed">فعال</span>' : '<span class="badge">تنظیم نشده</span>' ?>. الگوها در بخش «پیامک و ورود» تعریف می‌شوند.</p>
      <h3>اعلان مرورگر (Web Push)</h3>
      <label class="check"><input type="checkbox" name="push_enabled" value="1"<?= checked_attr(setting('push_enabled', '1') === '1') ?>> اعلان مرورگر فعال باشد</label>
      <?php input('ایمیل تماس برای سرویس‌های اعلان (VAPID)', 'push_contact', setting('push_contact', ''), 'email', false, 190); ?>
      <button>ذخیره تنظیمات</button>
    </form>
  </section>
  <section class="panel">
    <h2>کلید اعلان مرورگر</h2>
    <?php if (setting('push_vapid_public', '') !== ''): ?>
      <p class="muted">کلید عمومی (VAPID) این نصب:</p>
      <p class="share-link"><?= esc(setting('push_vapid_public')) ?></p>
      <p class="muted small">کلید خصوصی رمزنگاری‌شده در پایگاه داده نگهداری می‌شود. اعلان مرورگر فقط روی نشانی HTTPS کار می‌کند.</p>
    <?php else: ?>
      <p class="error">کلید اعلان ساخته نشده است (OpenSSL با منحنی P-256 روی هاست لازم است).</p>
    <?php endif; ?>
    <form method="post" data-confirm="با ساخت کلید جدید، اعلان مرورگر همه کاربران غیرفعال می‌شود و باید دوباره فعال کنند. ادامه می‌دهید؟"><?php form_token('push_regenerate'); ?><button class="secondary small">ساخت کلید جدید</button></form>
    <h3>آخرین خطاهای ارسال</h3>
    <ul class="tx-list"><?php foreach ($pushFailures as $f): ?><li><bdi><?= esc(jdate($f['created_at'], true)) ?></bdi> • <?= $f['http_status'] ? 'HTTP ' . fa_number($f['http_status']) . ' • ' : '' ?><?= esc((string) $f['error']) ?></li><?php endforeach; ?></ul>
    <?php if (!$pushFailures): ?><p class="muted">خطایی ثبت نشده است.</p><?php endif; ?>
    <h3>زمان‌بندی</h3>
    <p class="muted">یادآوری‌ها، صف انتظار، ارسال پیامک و اعلان مرورگر با Cron هر ۵ دقیقه اجرا می‌شوند. وضعیت آخرین اجرا در بخش «زمان‌بندی (Cron)» است.</p>
  </section>
</div>
