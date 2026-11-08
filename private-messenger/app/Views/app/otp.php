<?php use function PM\Support\e; $title = 'ورود'; include __DIR__ . '/../_head.php'; ?>
<main class="center"><div class="card">
  <p class="small muted">کد ارسال‌شده به <span class="ltr"><?= e($phone) ?></span> را وارد کنید.</p>
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/otp/verify">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <label class="field"><span>کد</span><input type="text" name="code" class="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label>
    <button class="btn block">ورود</button>
  </form>
  <p class="small"><a href="<?= e($base) ?>/">بازگشت</a></p>
</div></main>
</body></html>
