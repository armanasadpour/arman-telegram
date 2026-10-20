<?php
/**
 * Administrator sign-in (1.0.0-rc.13) for every role (owner, manager, finance, support, content, viewer).
 * With SMS on: ① mobile → SMS code, ② e-mail and password. An administrator without a mobile signs in with
 * the password once and registers a confirmed number. Without SMS: e-mail and password only.
 * @var string $error @var array $state @var bool $sms @var bool $first
 */
$step = $state['step'] ?? 'mobile';
$mobileInput = function (string $purpose) {
    ?>
    <form method="post" class="stack admin-auth-form" autocomplete="off">
      <?php form_token('admin_otp_send'); ?>
      <input type="hidden" name="purpose" value="<?= esc($purpose) ?>">
      <label class="admin-auth-label" for="admin-mobile">شماره موبایل</label>
      <input id="admin-mobile" class="mobile-input" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" maxlength="16" required placeholder="۰۹۱۲۳۴۵۶۷۸۹" dir="ltr" autofocus>
      <button class="block"><?= icon('sms') ?> دریافت کد تأیید</button>
    </form>
    <?php
};
$steps = function (int $current) {
    ?>
    <ol class="admin-auth-steps" aria-label="مراحل ورود">
      <li class="<?= $current === 1 ? 'current' : ($current > 1 ? 'done' : '') ?>"><span><?= $current > 1 ? icon('check') : '۱' ?></span>کد پیامکی</li>
      <li class="<?= $current === 2 ? 'current' : '' ?>"><span>۲</span>ایمیل و رمز</li>
    </ol>
    <?php
};
?>
<section class="auth-card admin-auth">
  <span class="admin-auth-icon" aria-hidden="true"><?= icon('shield') ?></span>
  <h1>ورود مدیران</h1>
  <p class="muted">مدیرکل، مدیر مالی، پشتیبانی، محتوا و ناظر</p>
  <?php if ($error): ?><p class="error" role="alert"><?= esc($error) ?></p><?php endif; ?>

  <?php if (!$sms): ?>
    <p class="notice small"><?= icon('info') ?> ورود دومرحله‌ای (کد پیامکی) پس از تنظیم کاوه‌نگار در «کاوه‌نگار و پیامک» فعال می‌شود. تا آن زمان با ایمیل و رمز وارد شوید.</p>
    <form method="post" class="stack">
      <?php form_token('login'); ?>
      <?php input('ایمیل', 'email', '', 'email'); ?>
      <?php input('رمز عبور', 'password', '', 'password'); ?>
      <button class="block">ورود</button>
    </form>

  <?php elseif ($step === 'code'): ?>
    <?php $steps(1); ?>
    <p>کد شش‌رقمی پیامک‌شده به <bdi class="auth-number" dir="ltr"><?= esc(fa_number($state['mobile'])) ?></bdi> را وارد کنید.</p>
    <form method="post" class="stack admin-auth-form" autocomplete="off">
      <?php form_token('admin_otp_verify'); ?>
      <label class="admin-auth-label" for="admin-code">کد تأیید</label>
      <input id="admin-code" class="mobile-input code-input" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9۰-۹]{6}" required dir="ltr" placeholder="------" autofocus>
      <button class="block"><?= icon('check') ?> تأیید کد</button>
    </form>
    <div class="admin-auth-links">
      <form method="post" class="inline-form"><?php form_token('admin_otp_send'); ?><input type="hidden" name="mobile" value="<?= esc($state['mobile']) ?>"><input type="hidden" name="purpose" value="<?= esc($state['purpose'] ?? 'login') ?>"><button class="link-button"><?= icon('refresh') ?> ارسال دوباره کد</button></form>
      <form method="post" class="inline-form"><?php form_token('admin_auth_reset'); ?><button class="link-button"><?= icon('edit') ?> تغییر شماره</button></form>
    </div>

  <?php elseif ($step === 'password'): ?>
    <?php $steps(2); ?>
    <p class="muted small">شماره <bdi dir="ltr"><?= esc(fa_number(mask_mobile($state['mobile']))) ?></bdi> تأیید شد. اکنون ایمیل و رمز عبور حساب مدیریت را وارد کنید.</p>
    <form method="post" class="stack" autocomplete="off">
      <?php form_token('login'); ?>
      <?php input('ایمیل', 'email', '', 'email'); ?>
      <?php input('رمز عبور', 'password', '', 'password'); ?>
      <button class="block"><?= icon('login') ?> ورود به پنل</button>
    </form>
    <div class="admin-auth-links"><form method="post" class="inline-form"><?php form_token('admin_auth_reset'); ?><button class="link-button"><?= icon('chevron-right') ?> شروع دوباره</button></form></div>

  <?php elseif ($step === 'register'): ?>
    <h2 class="admin-auth-sub">ثبت شماره موبایل مدیر</h2>
    <p class="muted small">برای حساب شما هنوز شماره موبایلی ثبت نشده است. از این پس ورود با کد پیامکی به همین شماره انجام می‌شود؛ شماره را وارد و با کد تأیید کنید.</p>
    <?php $mobileInput('register'); ?>
    <div class="admin-auth-links"><form method="post" class="inline-form"><?php form_token('admin_auth_reset'); ?><button class="link-button"><?= icon('chevron-right') ?> انصراف</button></form></div>

  <?php elseif ($first): ?>
    <h2 class="admin-auth-sub">مدیرانی که هنوز شماره موبایل ندارند</h2>
    <p class="muted small">یک بار با ایمیل و رمز وارد شوید؛ سپس شماره موبایل خود را ثبت و با کد تأیید می‌کنید. اگر شماره ثبت کرده‌اید، از «ورود با کد پیامکی» استفاده کنید.</p>
    <form method="post" class="stack" autocomplete="off">
      <?php form_token('login'); ?>
      <?php input('ایمیل', 'email', '', 'email'); ?>
      <?php input('رمز عبور', 'password', '', 'password'); ?>
      <button class="block">ادامه</button>
    </form>
    <div class="admin-auth-links"><a href="admin.php"><?= icon('chevron-right') ?> ورود با کد پیامکی</a></div>

  <?php else: ?>
    <?php $steps(1); ?>
    <p class="muted small">کد ورود به شماره موبایلی که برای حساب مدیریت شما ثبت شده پیامک می‌شود.</p>
    <?php $mobileInput('login'); ?>
    <div class="admin-auth-links"><a href="admin.php?first=1">شماره موبایل برای حساب شما ثبت نشده است؟</a></div>
  <?php endif; ?>
</section>
