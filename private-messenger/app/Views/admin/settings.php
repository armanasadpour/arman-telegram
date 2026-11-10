<?php use function PM\Support\e; use PM\Settings\Settings; use PM\Infra\Sms\SmsFactory; $title = 'تنظیمات'; include __DIR__ . '/_top.php';
$v = fn (string $k) => $s->get($k); $dis = $can_edit ? '' : 'disabled'; ?>
<h1>تنظیمات</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e($base) ?>/settings">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <div class="panel">
    <h2>روش ورود کاربران</h2>
    <?php foreach (Settings::AUTH_METHODS as $k => $label): ?>
      <label class="check"><input type="radio" name="auth_method" value="<?= e($k) ?>" <?= $v('auth.method') === $k ? 'checked' : '' ?> <?= $is_super && $can_edit ? '' : 'disabled' ?>> <?= e($label) ?><?= $k === 'password' ? ' (پیش‌فرض)' : '' ?></label>
    <?php endforeach; ?>
    <p class="small muted">فقط مدیرکل می‌تواند روش ورود را تغییر دهد. روش‌های پیامکی بدون تنظیم ارائه‌دهنده پیامک قابل انتخاب نیستند. در هیچ روشی کلید رمزنگاری پیام‌ها از رمز عبور یا کد پیامکی ساخته نمی‌شود.</p>
    <div class="grid2">
      <label class="field"><span>مدت اعتبار نشست کاربر (روز)</span><input type="number" name="session_days" value="<?= e($v('session.days')) ?>" <?= $dis ?>></label>
      <label class="field"><span>نام داخلی برنامه (فقط پس از ورود نمایش داده می‌شود)</span><input type="text" name="internal_name" value="<?= e($v('app.internal_name')) ?>" <?= $dis ?>></label>
    </div>
  </div>
  <div class="panel">
    <h2>فایل‌ها و فضا</h2>
    <div class="grid2">
      <label class="field"><span>سقف حجم هر فایل به مگابایت (۰ = بدون محدودیت نرم‌افزاری)</span><input type="number" name="files_max_mb" value="<?= e(intdiv((int)$v('files.max_bytes'), 1048576)) ?>" <?= $dis ?>></label>
      <label class="field"><span>سهمیه فضای هاست به گیگابایت (از cPanel؛ فقط برای نمایش، ۰ = نامعلوم)</span><input type="number" name="quota_gb" value="<?= e(intdiv((int)$v('storage.quota_bytes'), 1073741824)) ?>" <?= $dis ?>></label>
    </div>
    <p class="small muted">فایل‌ها ۷ روز پس از آپلود به‌صورت خودکار و غیرقابل بازیابی حذف می‌شوند (مگر برای حساب‌های دارای «نگهداری دائمی»). «بدون محدودیت» یعنی برنامه سقف مصنوعی ندارد؛ ظرفیت واقعی را فضای هاست تعیین می‌کند.</p>
  </div>
  <div class="panel">
    <h2>محدودیت ورود</h2>
    <div class="grid2">
      <label class="field"><span>سقف تلاش ورود هر IP در ۱۵ دقیقه</span><input type="number" name="login_ip" value="<?= e($v('login.per_ip_15min')) ?>" <?= $dis ?>></label>
      <label class="field"><span>قفل حساب پس از چند رمز اشتباه پیاپی</span><input type="number" name="login_fail" value="<?= e($v('login.user_fail_lock')) ?>" <?= $dis ?>></label>
      <label class="field"><span>مدت قفل (دقیقه)</span><input type="number" name="login_lock" value="<?= e($v('login.lock_minutes')) ?>" <?= $dis ?>></label>
    </div>
  </div>
  <div class="panel">
    <h2>پیامک و OTP</h2>
    <label class="field"><span>ارائه‌دهنده</span><select name="sms_provider" <?= $dis ?>>
      <?php foreach (SmsFactory::PROVIDERS as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('sms.provider') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
    </select></label>
    <div class="alert small">آداپتورهای کاوه‌نگار و sms.ir بر اساس مستندات عمومی آن‌ها پیاده‌سازی شده‌اند و هنوز با حساب واقعی تست نشده‌اند؛ پیش از استفاده، «ارسال آزمایشی» را انجام دهید.</div>
    <div class="grid2">
      <label class="field"><span>کلید API (برای حفظ مقدار فعلی خالی بگذارید)</span><input type="password" name="sms_api_key" class="ltr" autocomplete="off" placeholder="<?= $has_key ? '•••••• (ذخیره شده)' : '' ?>" <?= $dis ?>></label>
      <label class="field"><span>نام/شناسه قالب (کاوه‌نگار: نام قالب؛ sms.ir: templateId)</span><input type="text" name="sms_template" class="ltr" value="<?= e($v('sms.template')) ?>" <?= $dis ?>></label>
      <label class="field"><span>نام پارامتر کد (sms.ir)</span><input type="text" name="sms_param_name" class="ltr" value="<?= e($v('sms.param_name')) ?>" <?= $dis ?>></label>
      <label class="field"><span>متن پیامک (باید {code} داشته باشد؛ نام برنامه را ننویسید)</span><input type="text" name="otp_message" value="<?= e($v('otp.message')) ?>" <?= $dis ?>></label>
      <label class="field"><span>اعتبار کد (ثانیه)</span><input type="number" name="otp_ttl" value="<?= e($v('otp.ttl_seconds')) ?>" <?= $dis ?>></label>
      <label class="field"><span>حداکثر تلاش برای هر کد</span><input type="number" name="otp_attempts" value="<?= e($v('otp.max_attempts')) ?>" <?= $dis ?>></label>
    </div>
    <details><summary>تنظیمات وب‌هوک عمومی</summary>
      <div class="grid2">
        <label class="field"><span>متد</span><select name="sms_generic_method" <?= $dis ?>><option <?= $v('sms.generic_method') === 'POST' ? 'selected' : '' ?>>POST</option><option <?= $v('sms.generic_method') === 'GET' ? 'selected' : '' ?>>GET</option></select></label>
        <label class="field"><span>نام هدر احراز هویت (مقدار = کلید API)</span><input type="text" name="sms_generic_auth_header" class="ltr" value="<?= e($v('sms.generic_auth_header')) ?>" <?= $dis ?>></label>
      </div>
      <label class="field"><span>آدرس (https؛ متغیرها: {phone} {phone_local} {code} {message})</span><input type="text" name="sms_generic_url" class="ltr" value="<?= e($v('sms.generic_url')) ?>" <?= $dis ?>></label>
      <label class="field"><span>بدنه JSON (برای POST)</span><textarea name="sms_generic_body" class="ltr" rows="3" <?= $dis ?>><?= e($v('sms.generic_body')) ?></textarea></label>
    </details>
  </div>
  <?php if ($can_edit): ?><button class="btn">ذخیره تنظیمات</button><?php endif; ?>
</form>
<?php if ($can_edit): ?>
<form method="post" action="<?= e($base) ?>/settings/sms-test" class="panel mt">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <h2>ارسال پیامک آزمایشی (با تنظیمات ذخیره‌شده)</h2>
  <div class="row"><input type="tel" name="phone" class="ltr grow" placeholder="09xxxxxxxxx" required><button class="btn secondary">ارسال</button></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
