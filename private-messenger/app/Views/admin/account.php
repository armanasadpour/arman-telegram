<?php use function PM\Support\e; $title = 'حساب من'; include __DIR__ . '/_top.php'; ?>
<h1>حساب من</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="panel">
  <h2>ورود دومرحله‌ای (TOTP)</h2>
  <?php if ((int)$admin['totp_enabled'] === 1): ?>
    <p><span class="tag ok">فعال</span></p>
    <form method="post" action="<?= e($base) ?>/account/totp/disable"><?php include __DIR__ . '/../_csrf.php'; ?>
      <label class="field"><span>برای غیرفعال‌سازی، رمز عبور را وارد کنید</span><input type="password" name="password" class="ltr" required></label>
      <button class="btn danger">غیرفعال‌سازی</button></form>
  <?php elseif ($secret): ?>
    <p class="small">این کلید را در برنامه احراز هویت (مانند Google Authenticator، Aegis یا 2FAS) به‌صورت دستی وارد کنید، سپس کد ۶ رقمی را بنویسید.</p>
    <div class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></div>
    <details class="mt"><summary>آدرس otpauth</summary><div class="secret small"><?= e($uri) ?></div></details>
    <form method="post" action="<?= e($base) ?>/account/totp/confirm" class="mt"><?php include __DIR__ . '/../_csrf.php'; ?>
      <label class="field"><span>کد ۶ رقمی</span><input type="text" name="code" class="ltr" inputmode="numeric" maxlength="6" required></label>
      <button class="btn">تأیید و فعال‌سازی</button></form>
  <?php else: ?>
    <p><span class="tag warn">غیرفعال</span></p>
    <form method="post" action="<?= e($base) ?>/account/totp/start"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn">شروع فعال‌سازی</button></form>
  <?php endif; ?>
</div>
<div class="panel">
  <h2>تغییر رمز عبور</h2>
  <form method="post" action="<?= e($base) ?>/account/password"><?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>رمز فعلی</span><input type="password" name="current" class="ltr" autocomplete="current-password" required></label>
    <label class="field"><span>رمز جدید</span><input type="password" name="new" class="ltr" autocomplete="new-password" required></label>
    <label class="field"><span>تکرار رمز جدید</span><input type="password" name="confirm" class="ltr" autocomplete="new-password" required></label>
    <button class="btn">ذخیره</button></form>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
