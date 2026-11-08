<?php use function PM\Support\e; use PM\Admin\AdminService as A; $title = 'کاربر جدید'; include __DIR__ . '/_top.php'; ?>
<h1>کاربر جدید</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="<?= e($base) ?>/users/new" class="panel">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <label class="field"><span>نام کاربری (لاتین، برای ورود)</span><input type="text" name="username" class="ltr" value="<?= e($form['username'] ?? '') ?>" required></label>
  <label class="field"><span>نام نمایشی</span><input type="text" name="display_name" value="<?= e($form['display_name'] ?? '') ?>" required></label>
  <label class="field"><span>شماره موبایل (اختیاری؛ برای ورود با پیامک لازم است)</span><input type="tel" name="phone" class="ltr" value="<?= e($form['phone'] ?? '') ?>"></label>
  <?php if (A::can($admin, 'users.activate')): ?>
  <label class="check"><input type="checkbox" name="activate" value="1" <?= !empty($form['activate']) ? 'checked' : '' ?>> همین حالا فعال شود</label>
  <?php endif; ?>
  <p class="small muted">یک رمز موقت تصادفی ساخته و فقط یک بار نمایش داده می‌شود. کاربر در اولین ورود باید آن را تغییر دهد.</p>
  <button class="btn">ایجاد</button>
</form>
<?php include __DIR__ . '/_bottom.php'; ?>
