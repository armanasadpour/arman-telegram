<?php
/**
 * Sign-in and sign-up steps shared by login.php (full page, works without JavaScript) and the
 * bottom sheet / desktop dialog (assets/shell.js submits the same forms through the JSON API).
 *
 * @var string $step phone|code|profile — the step shown first
 * @var string $mobile verified or entered number (09xxxxxxxxx) or ''
 * @var string $error
 * @var int $resendIn seconds until "ارسال مجدد" is allowed
 * @var bool $available SMS sign-in enabled by the administrator
 * @var string $next where to go after signing in (validated by login_next())
 * @var string $idp id prefix, so the page and the sheet never repeat element ids
 */
$pages = published_pages();
$legal = [];
if (isset($pages['terms'])) {
    $legal[] = '<a href="' . esc(page_url('terms')) . '" target="_blank" rel="noopener">قوانین و مقررات</a>';
}
if (isset($pages['privacy'])) {
    $legal[] = '<a href="' . esc(page_url('privacy')) . '" target="_blank" rel="noopener">حریم خصوصی</a>';
}
$legalText = $legal ? implode(' و ', $legal) : 'قوانین و حریم خصوصی';
$hidden = fn(string $s) => $s === $step ? '' : ' hidden';
$nextField = $next !== '' ? '<input type="hidden" name="next" value="' . esc($next) . '">' : '';
$display = $mobile !== '' ? fa_number($mobile) : '';
?>
<div class="auth-box" data-auth-box data-step-current="<?= esc($step) ?>" data-resend="<?= (int) $resendIn ?>">
  <?php if (!$available): ?>
    <section class="auth-step">
      <h2 class="auth-title" id="<?= esc($idp) ?>title">ورود یا ثبت‌نام</h2>
      <p class="auth-lead">ورود پیامکی هنوز توسط مدیر سامانه فعال نشده است. لطفاً بعداً دوباره تلاش کنید یا با پشتیبانی تماس بگیرید.</p>
      <a class="button secondary auth-submit" href="support.php"><?= icon('chat') ?> تماس با پشتیبانی</a>
    </section>
  <?php else: ?>
  <section class="auth-step" data-step="phone"<?= $hidden('phone') ?>>
    <h2 class="auth-title" id="<?= esc($idp) ?>title" tabindex="-1">ورود یا ثبت‌نام</h2>
    <p class="auth-lead">برای ادامه، شماره موبایل ۱۱ رقمی خود را با ۰۹ وارد کنید.</p>
    <form method="post" action="login.php" data-auth-form="request" novalidate>
      <?php form_token('request'); ?><?= $nextField ?>
      <label class="sr-only" for="<?= esc($idp) ?>mobile">شماره موبایل</label>
      <input class="mobile-input auth-mobile" id="<?= esc($idp) ?>mobile" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" maxlength="16" required dir="ltr" placeholder="۰۹۱۲۳۴۵۶۷۸۹" value="<?= esc($mobile) ?>" data-mobile-input aria-describedby="<?= esc($idp) ?>mobile-error">
      <p class="field-error" id="<?= esc($idp) ?>mobile-error" data-error role="alert"><?= $step === 'phone' ? esc($error) : '' ?></p>
      <p class="auth-legal">ورود یا ثبت‌نام در نوبت‌یار به معنی پذیرش <?= $legalText ?> است.</p>
      <button class="auth-submit" data-submit><span>دریافت کد تأیید</span></button>
    </form>
  </section>

  <section class="auth-step" data-step="code"<?= $hidden('code') ?>>
    <h2 class="auth-title" tabindex="-1">تأیید شماره موبایل</h2>
    <p class="auth-lead">کد شش‌رقمی پیامک‌شده به <bdi class="auth-number" data-mobile-view><?= esc($display) ?></bdi> را وارد کنید.</p>
    <form method="post" action="login.php" class="auth-inline" data-auth-form="change"><?php form_token('change'); ?><?= $nextField ?><button class="link-button auth-edit" data-edit-number><?= icon('edit') ?> ویرایش شماره</button></form>
    <form method="post" action="login.php" data-auth-form="verify" novalidate>
      <?php form_token('verify'); ?><?= $nextField ?>
      <label class="sr-only" for="<?= esc($idp) ?>code">کد تأیید شش‌رقمی</label>
      <input class="otp-input" id="<?= esc($idp) ?>code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9۰-۹]{6}" required dir="ltr" data-otp-input aria-describedby="<?= esc($idp) ?>code-error">
      <p class="field-error" id="<?= esc($idp) ?>code-error" data-error role="alert"><?= $step === 'code' ? esc($error) : '' ?></p>
      <button class="auth-submit" data-submit><span>تأیید و ادامه</span></button>
    </form>
    <form method="post" action="login.php" class="auth-resend" data-auth-form="resend">
      <?php form_token('resend'); ?><?= $nextField ?>
      <span data-countdown aria-live="off"<?= $resendIn > 0 ? '' : ' hidden' ?>>ارسال مجدد تا <b data-countdown-value><?= esc(fa_number(sprintf('%02d:%02d', intdiv($resendIn, 60), $resendIn % 60))) ?></b> دیگر</span>
      <button class="link-button" data-resend-button<?= $resendIn > 0 ? ' hidden' : '' ?>><?= icon('refresh') ?> ارسال مجدد کد</button>
    </form>
  </section>

  <section class="auth-step" data-step="profile"<?= $hidden('profile') ?>>
    <h2 class="auth-title" tabindex="-1">تکمیل اطلاعات</h2>
    <p class="auth-lead">برای ساخت حساب، نام و نام خانوادگی خود را وارد کنید. این نام روی نوبت‌ها و رسیدها درج می‌شود.</p>
    <form method="post" action="login.php" data-auth-form="complete" novalidate>
      <?php form_token('complete'); ?><?= $nextField ?>
      <div class="auth-grid">
        <label>نام<input name="first_name" autocomplete="given-name" maxlength="50" required></label>
        <label>نام خانوادگی<input name="last_name" autocomplete="family-name" maxlength="50" required></label>
      </div>
      <label>شماره موبایل تأییدشده<input value="<?= esc($display) ?>" dir="ltr" readonly tabindex="-1" data-mobile-view></label>
      <label class="check auth-accept"><input type="checkbox" name="accept_terms" value="1" required> <span><?= $legalText ?> را خوانده‌ام و می‌پذیرم.</span></label>
      <p class="field-error" data-error role="alert"><?= $step === 'profile' ? esc($error) : '' ?></p>
      <button class="auth-submit" data-submit><span>ثبت‌نام و ورود</span></button>
    </form>
  </section>
  <?php endif; ?>
</div>
