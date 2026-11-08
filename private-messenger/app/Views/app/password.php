<?php use function PM\Support\e; $title = 'تغییر رمز عبور'; $nav = 'password'; include __DIR__ . '/../_head.php'; if (!$forced) { include __DIR__ . '/_nav.php'; } ?>
<main class="<?= $forced ? 'center' : 'page' ?>"><div class="<?= $forced ? 'card' : 'panel' ?>">
  <h1>تغییر رمز عبور</h1>
  <?php if ($forced): ?><div class="alert warn">برای ادامه، رمز موقت را به یک رمز عبور جدید تغییر دهید.</div><?php endif; ?>
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/password">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
    <label class="field"><span>رمز عبور فعلی</span><input type="password" name="current" class="ltr" autocomplete="current-password" required></label>
    <label class="field"><span>رمز عبور جدید (حداقل ۱۰ کاراکتر)</span><input type="password" name="new" class="ltr" autocomplete="new-password" minlength="10" required></label>
    <label class="field"><span>تکرار رمز عبور جدید</span><input type="password" name="confirm" class="ltr" autocomplete="new-password" minlength="10" required></label>
    <button class="btn block">ذخیره</button>
  </form>
  <p class="small muted">پس از تغییر رمز، سایر نشست‌های شما باطل می‌شوند. رمز عبور فقط برای ورود است و هیچ کلید رمزنگاری از آن ساخته نمی‌شود.</p>
  <?php if ($forced): ?><form method="post" action="<?= e($base) ?>/logout"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary block">خروج</button></form><?php endif; ?>
</div></main>
</body></html>
