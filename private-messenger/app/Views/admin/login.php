<?php use function PM\Support\e; $title = 'ورود'; include __DIR__ . '/../_head.php'; ?>
<main class="center"><div class="card">
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/login">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>نام کاربری</span><input type="text" name="username" class="ltr" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" required <?= $totp ? '' : 'autofocus' ?>></label>
    <label class="field"><span>رمز عبور</span><input type="password" name="password" class="ltr" autocomplete="current-password" required></label>
    <?php if ($totp): ?>
    <label class="field"><span>کد تأیید دومرحله‌ای</span><input type="text" name="totp" class="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label>
    <?php endif; ?>
    <button class="btn block">ورود</button>
  </form>
</div></main>
</body></html>
