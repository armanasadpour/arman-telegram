<?php use function PM\Support\e; $title = 'نصب'; include __DIR__ . '/../_head.php';
$warns = array_filter($checks, fn ($c) => in_array($c['status'], ['fail'], true)); ?>
<div class="page">
  <h1>نصب — فقط یک مرحله</h1>
  <?php if ($blocked): ?>
    <div class="alert error">سرور هنوز آماده نیست. موارد قرمز زیر را برطرف کنید و صفحه را دوباره باز کنید:</div>
    <div class="panel"><ul><?php foreach ($warns as $c): ?><li><b><?= e($c['name']) ?>:</b> <?= e($c['value']) ?> — <?= e($c['note']) ?></li><?php endforeach; ?></ul></div>
  <?php else: ?>
  <div class="alert ok">سرور شما آماده است ✔</div>
  <?php if ($error): ?><div class="alert error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($base) ?>/" class="panel">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <h2>۱. اطلاعات ورود به cPanel</h2>
    <p class="small muted">همان نام کاربری و رمزی که با آن وارد cPanel می‌شوید. فقط یک بار برای ساخت خودکار دیتابیس استفاده می‌شود و هیچ‌جا ذخیره نمی‌شود.</p>
    <input type="hidden" name="db_mode" value="auto" id="mode-auto-hidden">
    <div class="grid2">
      <label class="field"><span>نام کاربری cPanel</span><input type="text" name="cpanel_user" class="ltr" value="<?= e($form['cpanel_user']) ?>" autocomplete="off"></label>
      <label class="field"><span>رمز cPanel</span><input type="password" name="cpanel_pass" class="ltr" autocomplete="off"></label>
    </div>
    <details <?= $form['db_mode'] === 'manual' ? 'open' : '' ?>>
      <summary>دیتابیس را خودم ساخته‌ام (روش دستی)</summary>
      <label class="check"><input type="checkbox" name="db_mode" value="manual" <?= $form['db_mode'] === 'manual' ? 'checked' : '' ?>> از دیتابیس زیر استفاده کن</label>
      <div class="grid2">
        <label class="field"><span>نام دیتابیس</span><input type="text" name="db_name" class="ltr" value="<?= e($form['db_name']) ?>"></label>
        <label class="field"><span>نام کاربری دیتابیس</span><input type="text" name="db_user" class="ltr" value="<?= e($form['db_user']) ?>"></label>
        <label class="field"><span>رمز دیتابیس</span><input type="password" name="db_pass" class="ltr" autocomplete="off"></label>
        <label class="field"><span>میزبان</span><input type="text" name="db_host" class="ltr" value="<?= e($form['db_host']) ?>"></label>
      </div>
      <input type="hidden" name="db_port" value="<?= e($form['db_port']) ?>">
    </details>

    <h2 class="mt">۲. رمز مدیر سامانه را انتخاب کنید</h2>
    <div class="grid2">
      <label class="field"><span>نام کاربری مدیر</span><input type="text" name="admin_username" class="ltr" value="<?= e($form['admin_username']) ?>" required></label>
      <span></span>
      <label class="field"><span>رمز مدیر (حداقل ۱۰ کاراکتر)</span><input type="password" name="admin_password" class="ltr" autocomplete="new-password" required></label>
      <label class="field"><span>تکرار رمز مدیر</span><input type="password" name="admin_password2" class="ltr" autocomplete="new-password" required></label>
    </div>
    <button class="btn block">نصب کن</button>
  </form>
  <?php endif; ?>
  <details class="panel small"><summary>جزئیات بررسی سرور</summary>
    <div class="table-wrap"><table>
      <?php foreach ($checks as $c): ?>
      <tr><td><?= e($c['name']) ?></td><td class="ltr"><?= e($c['value']) ?></td>
          <td><span class="tag <?= e($c['status']) ?>"><?= e(['ok' => 'مناسب', 'warn' => 'هشدار', 'fail' => 'مانع نصب', 'info' => 'اطلاع'][$c['status']]) ?></span></td>
          <td class="muted"><?= e($c['note']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </details>
</div>
</body></html>
