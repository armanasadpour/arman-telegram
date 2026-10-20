<?php
/** @var array $settings @var ?array $account @var array $events @var array $templates @var array $filters @var array $today @var array $logs @var array $otps @var array $authEvents */
$s = $settings;
$otp = $s['otp'];
?>
<?php if ($s['legacyFile']): ?>
  <p class="error">فایل قدیمی private/sms.php هنوز روی هاست وجود دارد. تنظیمات آن به‌صورت رمزشده منتقل شده است؛ این فایل را از طریق File Manager حذف کنید.</p>
<?php endif; ?>
<?php if ($s['dev']): ?>
  <p class="notice">حالت آزمایشی فعال است: هیچ پیامکی به کاوه‌نگار ارسال نمی‌شود و کدها فقط در گزارش زیر دیده می‌شوند. این حالت فقط در محیط توسعه (environment=development در config.php) در دسترس است.</p>
<?php endif; ?>

<div class="stat-grid">
  <article class="stat-card"><?= icon('sms') ?><span>وضعیت سرویس</span><strong><?= $s['enabled'] ? 'فعال' : 'غیرفعال' ?></strong></article>
  <article class="stat-card"><?= icon('key') ?><span>کلید API</span><strong class="small"><?= esc($s['keyHint']) ?></strong></article>
  <article class="stat-card"><?= icon('wallet') ?><span>اعتبار حساب کاوه‌نگار</span>
    <strong class="small"><?= $account ? fa_number(number_format($account['remaincredit'], 0, '.', '٬')) . ' ریال' : 'استعلام نشده' ?></strong>
    <?php if ($account): ?><small>آخرین استعلام: <?= esc(jdate($account['checked_at'], true)) ?><?= $account['expiredate'] ? ' • انقضا: ' . esc(jdate(date('Y-m-d', $account['expiredate']))) : '' ?></small><?php endif; ?>
    <form method="post" class="inline-form"><?php form_token('sms_account'); ?><button class="link-button">استعلام از کاوه‌نگار</button></form>
  </article>
  <article class="stat-card"><?= icon('chart') ?><span>پیامک‌های امروز</span>
    <strong><?= fa_number(array_sum($today)) ?></strong>
    <small>موفق <?= fa_number(($today['sent'] ?? 0) + ($today['test'] ?? 0)) ?> • ناموفق <?= fa_number($today['failed'] ?? 0) ?> • در صف <?= fa_number($today['queued'] ?? 0) ?></small>
  </article>
</div>

<div class="grid-2 align-start">
  <section class="panel">
    <h2>تنظیمات کاوه‌نگار و ورود</h2>
    <form method="post" class="stack">
      <?php form_token('sms_settings'); ?>
      <?php input('شماره خط فرستنده پیامک متنی', 'sms_sender', setting('sms_sender'), 'text', false, 30); ?>
      <?php input('کلید API (خالی بگذارید تا کلید قبلی حفظ شود)', 'api_key', '', 'password', false, 200); ?>
      <p class="muted">کلید با رمزنگاری در پایگاه داده نگهداری می‌شود و پس از ذخیره نمایش داده نمی‌شود.</p>
      <p><a href="admin.php?tab=sms_templates">تنظیم متن، روش ارسال و فعال‌سازی کد ورود در «الگوهای پیامک»</a></p>
      <div class="form-grid">
        <?php input('سقف پیامک روزانه کل سامانه', 'daily_limit', $s['daily_limit'], 'number', true, 6); ?>
        <?php input('تلاش مجدد پیامک‌های صف (۱ تا ۱۰)', 'sms_max_attempts', $s['max_attempts'], 'number', true, 2); ?>
      </div>
      <h3>امنیت رمز یک‌بارمصرف</h3>
      <div class="form-grid">
        <?php input('اعتبار کد (ثانیه، ۶۰ تا ۹۰۰)', 'otp_ttl_seconds', (string) $otp['ttl'], 'number', true, 3); ?>
        <?php input('فاصله ارسال مجدد (ثانیه)', 'otp_resend_seconds', (string) $otp['resend'], 'number', true, 3); ?>
        <?php input('سقف تلاش ناموفق هر کد', 'otp_max_attempts', (string) $otp['attempts'], 'number', true, 2); ?>
        <?php input('سقف درخواست هر شماره در ساعت', 'otp_phone_hourly', (string) $otp['phone_hourly'], 'number', true, 2); ?>
        <?php input('سقف درخواست هر IP در ۱۵ دقیقه', 'otp_ip_15min', (string) $otp['ip_15min'], 'number', true, 3); ?>
        <?php input('سقف شماره‌های متفاوت هر IP در ساعت', 'otp_ip_distinct_hourly', (string) $otp['ip_distinct'], 'number', true, 3); ?>
      </div>
      <p class="muted">پس از سه برابر سقف تلاش ناموفق در یک ساعت، ورود آن شماره موقتاً قفل می‌شود. کدها فقط به‌صورت هش ذخیره و پس از استفاده باطل می‌شوند.</p>
      <label class="check"><input type="checkbox" name="enabled"<?= checked_attr($s['enabled']) ?>> ارسال پیامک و ورود پیامکی فعال باشد</label>
      <?php if ($s['environment'] === 'development'): ?>
        <label class="check"><input type="checkbox" name="dev_mode"<?= checked_attr($s['dev']) ?>> حالت آزمایشی بدون ارسال واقعی (فقط محیط توسعه)</label>
      <?php endif; ?>
      <button>ذخیره تنظیمات</button>
    </form>
  </section>

  <section class="panel">
    <h2>ارسال پیامک آزمایشی</h2>
    <p class="muted">با متغیرهای نمونه هر رویداد و از طریق الگوی ثبت‌شده ارسال می‌شود. پذیرش توسط کاوه‌نگار، تضمین رسیدن به گوشی نیست.</p>
    <form method="post" class="stack">
      <?php form_token('sms_test'); ?>
      <label>رویداد
        <select name="event">
          <?php foreach ($events as $key => $e): ?><option value="<?= esc($key) ?>"><?= esc($e['label']) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php input('شماره موبایل گیرنده', 'mobile', '', 'tel', true, 11); ?>
      <button>ارسال آزمایشی</button>
    </form>
  </section>
</div>

<p><a class="button secondary" href="admin.php?tab=sms_templates">مدیریت متن و تگ‌های الگوی پیامک</a> <a class="button secondary" href="admin.php?tab=sms_send">ارسال پیامک</a></p>
<section class="panel">
  <div class="panel-head"><h2>گزارش ارسال پیامک</h2></div>
  <form method="get" class="filter-bar">
    <input type="hidden" name="tab" value="sms">
    <label>وضعیت
      <select name="status"><option value="">همه</option>
        <?php foreach (['queued', 'sending', 'sent', 'failed', 'unknown', 'test', 'cancelled'] as $st): ?>
          <option value="<?= $st ?>"<?= selected_attr($filters['status'] === $st) ?>><?= esc(sms_status_label($st)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>رویداد
      <select name="event"><option value="">همه</option>
        <?php foreach ($events as $key => $e): ?><option value="<?= esc($key) ?>"<?= selected_attr($filters['event'] === $key) ?>><?= esc($e['label']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <button>فیلتر</button>
  </form>
  <div class="table-wrap">
    <table>
      <thead><tr><th>زمان</th><th>رویداد</th><th>گیرنده</th><th>وضعیت</th><th>شناسه سرویس</th><th>تلاش</th><th>خطا</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $m): ?>
        <tr>
          <td><bdi><?= esc(jdate($m['created_at'], true)) ?></bdi></td>
          <td><?= esc($events[$m['event']]['label'] ?? $m['event']) ?></td>
          <td><bdi><?= esc(mask_mobile($m['mobile'])) ?></bdi></td>
          <td><span class="badge sms-<?= esc($m['status']) ?>"><?= esc(sms_status_label($m['status'])) ?></span>
            <?php if ($m['status'] === 'test' && $s['dev'] && $m['tokens']): ?><br><small dir="ltr"><?= esc($m['tokens']) ?></small><?php endif; ?></td>
          <td><bdi><?= esc($m['provider_id'] ?? '—') ?></bdi></td>
          <td><?= fa_number($m['attempts']) ?></td>
          <td><small><?= esc($m['error'] ?? '') ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$logs): ?><p class="empty-state">پیامکی ثبت نشده است.</p><?php endif; ?>
</section>

<details class="panel">
  <summary>آخرین درخواست‌های کد ورود</summary>
  <div class="table-wrap">
    <table>
      <thead><tr><th>موبایل</th><th>وضعیت ارسال</th><th>زمان</th><th>تلاش ناموفق</th><th>استفاده‌شده</th></tr></thead>
      <tbody>
      <?php $labels = ['accepted' => 'پذیرفته‌شده توسط سرویس', 'pending' => 'در حال ارسال', 'failed' => 'ناموفق']; ?>
      <?php foreach ($otps as $o): ?>
        <tr>
          <td><bdi><?= esc(mask_mobile($o['mobile'])) ?></bdi></td>
          <td><?= esc($labels[$o['delivery_status']] ?? 'نامشخص') ?></td>
          <td><bdi><?= esc(jdate(date('Y-m-d H:i:s', (int) $o['created_at']), true)) ?></bdi></td>
          <td><?= fa_number($o['attempts']) ?></td>
          <td><?= $o['consumed_at'] ? 'بله' : 'خیر' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>

<section class="panel">
  <h2>رویدادهای ورود و ثبت‌نام</h2>
  <p class="muted">کد اشتباه، کد منقضی، تلاش حساب غیرفعال، درخواست مشکوک و ثبت‌نام‌های جدید. شماره‌ها پوشیده‌اند و هیچ کدی ذخیره یا نمایش داده نمی‌شود.</p>
  <?php if (!$authEvents): ?><p class="empty-state">رویدادی ثبت نشده است.</p><?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>زمان</th><th>رویداد</th><th>شماره</th><?php if (admin_sensitive()): ?><th>IP</th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($authEvents as $a): ?>
        <tr>
          <td><bdi><?= esc(jdate($a['created_at'], true)) ?></bdi></td>
          <td><?= esc(audit_label($a['action'])) ?></td>
          <td><bdi><?= esc($a['detail'] ?? '—') ?></bdi></td>
          <?php if (admin_sensitive()): ?><td dir="ltr"><?= esc($a['ip'] ?? '') ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>
