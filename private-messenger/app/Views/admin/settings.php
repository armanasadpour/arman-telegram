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
    </div>
  </div>
  <div class="panel" id="look">
    <h2>نام، لوگو و رنگ</h2>
    <p class="small muted">نام، لوگو و رنگ فقط <b>پس از ورود</b> نمایش داده می‌شوند. صفحه ورود عمداً بی‌نام و بی‌نشان می‌ماند تا مشخص نباشد این سایت پیام‌رسان است.</p>
    <div class="grid2">
      <label class="field"><span>نام برنامه (عنوان داخل برنامه و نام برنامه نصب‌شده روی گوشی)</span><input type="text" name="internal_name" maxlength="60" value="<?= e($v('app.internal_name')) ?>" <?= $dis ?>></label>
      <div class="field"><span>رنگ اصلی</span><div class="row wrap">
        <?php foreach (Settings::ACCENTS as $hex => $label): ?><label class="swatch" title="<?= e($label) ?>"><input type="radio" name="accent" value="<?= e($hex) ?>" <?= $v('app.accent') === $hex ? 'checked' : '' ?> <?= $dis ?>><span class="sw-<?= e(substr($hex, 1)) ?>"></span> <?= e($label) ?></label><?php endforeach; ?>
      </div></div>
    </div>
  </div>
  <div class="panel">
    <h2>اعلان‌ها</h2>
    <label class="check"><input type="checkbox" name="notify_enabled" value="1" <?= $v('notify.enabled') ? 'checked' : '' ?> <?= $dis ?>> اعلان مرورگر برای پیام و تماس جدید وقتی برنامه در پس‌زمینه است (کاربر باید در مرورگر اجازه دهد)</label>
    <label class="field"><span>متن اعلان پیام</span><select name="notify_preview" <?= $dis ?>>
      <option value="none" <?= $v('notify.preview') === 'none' ? 'selected' : '' ?>>فقط «پیام جدید» — بدون نام فرستنده (امن‌ترین)</option>
      <option value="sender" <?= $v('notify.preview') === 'sender' ? 'selected' : '' ?>>نام فرستنده یا گروه</option>
    </select></label>
    <p class="small muted">متن پیام هرگز در اعلان نمایش داده نمی‌شود (اعلان روی صفحه قفل گوشی دیده می‌شود). اعلان فقط وقتی برنامه باز است (حتی در پس‌زمینه) کار می‌کند؛ اعلان در حالت بسته (Web Push) در این نسخه وجود ندارد.</p>
  </div>
  <div class="panel">
    <h2>تماس صوتی و تصویری</h2>
    <p class="small muted">تماس‌ها درون‌برنامه‌ای و از طریق اینترنت (WebRTC) هستند. صدا و تصویر با DTLS-SRTP رمزنگاری می‌شود و اطلاعات برقراری تماس داخل پیام‌های رمزنگاری‌شده سرتاسری منتقل می‌شود.</p>
    <label class="field"><span>سرورهای STUN (هر کدام در یک خط؛ پیش‌فرض STUN عمومی گوگل است که IP تماس‌گیرنده را می‌بیند — خالی یعنی فقط شبکه محلی)</span>
      <textarea name="calls_stun" class="ltr" rows="2" <?= $dis ?>><?= e($v('calls.stun')) ?></textarea></label>
    <label class="field"><span>سرور TURN (اختیاری؛ برای شبکه‌هایی که اتصال مستقیم ندارند) — مثلاً turn:turn.example.com:3478 و turns:turn.example.com:443?transport=tcp</span>
      <textarea name="calls_turn_urls" class="ltr" rows="2" <?= $dis ?>><?= e($v('calls.turn_urls')) ?></textarea></label>
    <div class="grid2">
      <label class="field"><span>نوع احراز هویت TURN</span><select name="calls_turn_mode" <?= $dis ?>>
        <?php foreach (['none' => 'بدون TURN', 'secret' => 'راز مشترک (coturn use-auth-secret) — پیشنهادی', 'static' => 'نام کاربری و رمز ثابت'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('calls.turn_mode') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select></label>
      <label class="field"><span>راز مشترک TURN (خالی = حفظ مقدار فعلی)</span><input type="password" name="calls_turn_secret" class="ltr" autocomplete="off" placeholder="<?= $s->hasSecret('calls.turn_secret') ? '•••••• (ذخیره شده)' : '' ?>" <?= $dis ?>></label>
      <label class="field"><span>نام کاربری TURN (حالت ثابت)</span><input type="text" name="calls_turn_username" class="ltr" value="<?= e($v('calls.turn_username')) ?>" <?= $dis ?>></label>
      <label class="field"><span>رمز TURN (حالت ثابت؛ خالی = حفظ مقدار فعلی)</span><input type="password" name="calls_turn_password" class="ltr" autocomplete="off" <?= $dis ?>></label>
    </div>
    <label class="check"><input type="checkbox" name="calls_relay_only" value="1" <?= $v('calls.relay_only') ? 'checked' : '' ?> <?= $dis ?>> همه تماس‌ها فقط از طریق TURN (IP کاربران از هم پنهان می‌ماند؛ نیازمند TURN)</label>
  </div>
  <div class="panel">
    <h2>فایل‌ها و فضا</h2>
    <div class="grid2">
      <label class="field"><span>سقف حجم هر فایل به مگابایت (۰ = بدون محدودیت نرم‌افزاری)</span><input type="number" name="files_max_mb" value="<?= e(intdiv((int)$v('files.max_bytes'), 1048576)) ?>" <?= $dis ?>></label>
      <label class="field"><span>سهمیه فضای هاست به گیگابایت (از cPanel؛ فقط برای نمایش، ۰ = نامعلوم)</span><input type="number" name="quota_gb" value="<?= e(intdiv((int)$v('storage.quota_bytes'), 1073741824)) ?>" <?= $dis ?>></label>
    </div>
    <label class="field"><span>مدت نگهداری فایل روی سرور (روز؛ پیش‌فرض ۷)</span><input type="number" name="retention_days" min="1" max="3650" value="<?= e($v('files.retention_days')) ?>" <?= $is_super ? $dis : 'disabled' ?>></label>
    <p class="small muted">فایل‌ها پس از این مدت به‌صورت خودکار و غیرقابل بازیابی حذف می‌شوند (مگر برای حساب‌های دارای «نگهداری دائمی»). تغییر این عدد فقط روی فایل‌های جدید اثر دارد و فقط مدیرکل می‌تواند آن را تغییر دهد. «بدون محدودیت» یعنی برنامه سقف مصنوعی ندارد؛ ظرفیت واقعی را فضای هاست تعیین می‌کند.</p>
  </div>
  <div class="panel">
    <h2>محدودیت نرخ درخواست</h2>
    <div class="grid2">
      <label class="field"><span>ارسال پیام/رویداد برای هر کاربر در ساعت</span><input type="number" name="limit_send" value="<?= e($v('limits.send_per_hour')) ?>" <?= $dis ?>></label>
      <label class="field"><span>جست‌وجوی کاربر برای هر کاربر در ساعت</span><input type="number" name="limit_search" value="<?= e($v('limits.search_per_hour')) ?>" <?= $dis ?>></label>
      <label class="field"><span>دریافت کلید یک‌بارمصرف (شروع گفتگو با دستگاه جدید) در ساعت</span><input type="number" name="limit_claim" value="<?= e($v('limits.claim_per_hour')) ?>" <?= $dis ?>></label>
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
<div class="panel mt">
  <h2>لوگو</h2>
  <p>لوگوی سفارشی: <b><?= $v('app.logo') !== '' ? 'بارگذاری شده' : 'ندارد (نماد پیش‌فرض)' ?></b></p>
  <p class="small muted">لوگو فقط پس از ورود و به‌عنوان نماد برنامه نصب‌شده (PWA) روی گوشی/رایانه استفاده می‌شود. فایل PNG مربعی، ۱۹۲ تا ۱۰۲۴ پیکسل (پیشنهاد: ۵۱۲×۵۱۲)، حداکثر ۱ مگابایت.</p>
  <form method="post" action="<?= e($base) ?>/settings/logo" enctype="multipart/form-data" class="row">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <input type="file" name="logo" accept="image/png" required class="grow"><button class="btn secondary">بارگذاری</button>
  </form>
  <?php if ($v('app.logo') !== ''): ?><form method="post" action="<?= e($base) ?>/settings/logo/delete" class="mt"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn danger sm">حذف لوگو</button></form><?php endif; ?>
</div>
<form method="post" action="<?= e($base) ?>/settings/sms-test" class="panel mt">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <h2>ارسال پیامک آزمایشی (با تنظیمات ذخیره‌شده)</h2>
  <div class="row"><input type="tel" name="phone" class="ltr grow" placeholder="09xxxxxxxxx" required><button class="btn secondary">ارسال</button></div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
