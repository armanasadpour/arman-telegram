<?php use function PM\Support\e; $title = 'نصب'; include __DIR__ . '/../_head.php'; ?>
<div class="page">
  <h1>نصب — بررسی هاست و تنظیمات</h1>
  <div class="panel">
    <h2>بررسی قابلیت‌های هاست</h2>
    <div class="table-wrap"><table>
      <tr><th>مورد</th><th>مقدار</th><th>وضعیت</th><th>توضیح</th></tr>
      <?php foreach ($checks as $c): ?>
      <tr><td><?= e($c['name']) ?></td><td class="ltr"><?= e($c['value']) ?></td>
          <td><span class="tag <?= e($c['status']) ?>"><?= e(['ok' => 'مناسب', 'warn' => 'هشدار', 'fail' => 'مانع نصب', 'info' => 'اطلاع'][$c['status']]) ?></span></td>
          <td class="small muted"><?= e($c['note']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </div>
  <?php if ($blocked): ?>
    <div class="alert error">موارد «مانع نصب» باید پیش از ادامه برطرف شوند. پس از رفع، صفحه را دوباره بارگذاری کنید.</div>
  <?php else: ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/" class="panel">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <h2>دیتابیس (یک دیتابیس خالی MySQL/MariaDB)</h2>
    <div class="grid2">
      <label class="field"><span>میزبان</span><input type="text" name="db_host" class="ltr" value="<?= e($form['db_host']) ?>" required></label>
      <label class="field"><span>پورت</span><input type="text" name="db_port" class="ltr" value="<?= e($form['db_port']) ?>" required></label>
      <label class="field"><span>نام دیتابیس</span><input type="text" name="db_name" class="ltr" value="<?= e($form['db_name']) ?>" required></label>
      <label class="field"><span>نام کاربری دیتابیس</span><input type="text" name="db_user" class="ltr" value="<?= e($form['db_user']) ?>" required></label>
      <label class="field"><span>رمز دیتابیس</span><input type="password" name="db_pass" class="ltr" autocomplete="off"></label>
    </div>
    <h2>حساب مدیرکل</h2>
    <div class="grid2">
      <label class="field"><span>نام کاربری</span><input type="text" name="admin_username" class="ltr" value="<?= e($form['admin_username']) ?>" required></label>
      <label class="field"><span>نام نمایشی</span><input type="text" name="admin_display" value="<?= e($form['admin_display']) ?>"></label>
      <label class="field"><span>رمز عبور (حداقل ۱۰ کاراکتر)</span><input type="password" name="admin_password" class="ltr" autocomplete="new-password" required></label>
      <label class="field"><span>تکرار رمز عبور</span><input type="password" name="admin_password2" class="ltr" autocomplete="new-password" required></label>
    </div>
    <p class="small muted">پس از نصب، دو آدرس مخفی تصادفی (کاربران و مدیریت) فقط یک بار نمایش داده می‌شود. صفحه اصلی دامنه و هر آدرس دیگری خطای 404 خواهد داد.</p>
    <button class="btn">نصب</button>
  </form>
  <?php endif; ?>
</div>
</body></html>
