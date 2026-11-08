<?php use function PM\Support\e; $title = 'ورود'; include __DIR__ . '/../_head.php'; ?>
<main class="center"><div class="card">
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($method === 'otp'): ?>
  <form method="post" action="<?= e($base) ?>/otp/request">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>شماره موبایل</span><input type="tel" name="phone" class="ltr" inputmode="tel" autocomplete="tel" required autofocus></label>
    <button class="btn block">دریافت کد</button>
  </form>
  <?php else: ?>
  <form method="post" action="<?= e($base) ?>/login">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>نام کاربری</span><input type="text" name="username" class="ltr" value="<?= e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus></label>
    <label class="field"><span>رمز عبور</span><input type="password" name="password" class="ltr" autocomplete="current-password" required></label>
    <button class="btn block">ورود</button>
  </form>
  <?php endif; ?>
</div></main>
</body></html>
