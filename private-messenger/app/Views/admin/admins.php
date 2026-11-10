<?php use function PM\Support\e; use PM\Support\Fa; $title = 'مدیران'; include __DIR__ . '/_top.php';
$grantable = array_diff_key($perms, array_flip($super_only)); ?>
<h1>مدیران و نقش‌ها</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="panel table-wrap"><table>
  <tr><th>نام کاربری</th><th>نقش</th><th>TOTP</th><th>آخرین ورود</th><th>وضعیت</th><th></th></tr>
  <?php foreach ($list as $a): $self = (int)$a['id'] === (int)$admin['id']; ?>
  <tr><td class="ltr"><?= e($a['username']) ?></td>
      <td><?php if ($self): ?><?= e($a['role_name']) ?><?php else: ?>
        <form method="post" action="<?= e($base) ?>/admins/<?= (int)$a['id'] ?>/role" class="row"><?php include __DIR__ . '/../_csrf.php'; ?>
          <select name="role_id" aria-label="نقش"><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === (int)$a['role_id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
          <button class="btn secondary sm">تغییر نقش</button></form><?php endif; ?></td>
      <td><?= $a['totp_enabled'] ? '<span class="tag ok">فعال</span>' : '<span class="tag warn">غیرفعال</span>' ?></td>
      <td><?= e(Fa::dateTime($a['last_login_at'])) ?></td>
      <td><span class="tag <?= $a['status'] === 'active' ? 'ok' : 'fail' ?>"><?= $a['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></span></td>
      <td><?php if (!$self): ?><form method="post" action="<?= e($base) ?>/admins/<?= (int)$a['id'] ?>/status" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?>
        <input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'disabled' : 'active' ?>">
        <button class="btn sm <?= $a['status'] === 'active' ? 'danger' : 'secondary' ?>"><?= $a['status'] === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table>
<p class="small muted">تغییر نقش یک مدیر، نشست‌های فعال او را می‌بندد تا مجوزهای جدید فوراً اعمال شود.</p></div>
<form method="post" action="<?= e($base) ?>/admins" class="panel">
  <?php include __DIR__ . '/../_csrf.php'; ?>
  <h2>مدیر جدید</h2>
  <div class="grid2">
    <label class="field"><span>نام کاربری</span><input type="text" name="username" class="ltr" required></label>
    <label class="field"><span>نام نمایشی</span><input type="text" name="display_name"></label>
    <label class="field"><span>رمز عبور اولیه (حداقل ۱۰ کاراکتر)</span><input type="password" name="password" class="ltr" autocomplete="new-password" required></label>
    <label class="field"><span>نقش</span><select name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select></label>
  </div>
  <button class="btn">ایجاد</button>
</form>
<div class="panel">
  <h2>نقش‌ها و مجوزها</h2>
  <?php foreach ($roles as $r): ?>
    <details class="role mt">
      <summary><b><?= e($r['name']) ?></b> — <?= (int)$r['is_super'] === 1 ? 'همه مجوزها' : e(Fa::digits(count($r['permissions']))) . ' مجوز' ?> · <?= e(Fa::digits($r['admins'])) ?> مدیر</summary>
      <?php if ((int)$r['is_super'] === 1): ?>
        <p class="small muted">نقش مدیرکل همه مجوزها را دارد و قابل ویرایش یا حذف نیست.</p>
      <?php else: ?>
        <form method="post" action="<?= e($base) ?>/roles/<?= (int)$r['id'] ?>"><?php include __DIR__ . '/../_csrf.php'; ?>
          <label class="field"><span>نام نقش</span><input type="text" name="name" value="<?= e($r['name']) ?>" maxlength="50" required></label>
          <div class="perm-grid"><?php foreach ($grantable as $code => $label): ?><label class="check"><input type="checkbox" name="perms[]" value="<?= e($code) ?>" <?= in_array($code, $r['permissions'], true) ? 'checked' : '' ?>> <?= e($label) ?></label><?php endforeach; ?></div>
          <button class="btn secondary sm">ذخیره نقش</button></form>
        <?php if ((int)$r['admins'] === 0): ?><form method="post" action="<?= e($base) ?>/roles/<?= (int)$r['id'] ?>/delete" class="mt"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn danger sm">حذف نقش</button></form><?php endif; ?>
      <?php endif; ?>
    </details>
  <?php endforeach; ?>
  <details class="mt"><summary><b>+ نقش جدید</b></summary>
    <form method="post" action="<?= e($base) ?>/roles"><?php include __DIR__ . '/../_csrf.php'; ?>
      <label class="field"><span>نام نقش</span><input type="text" name="name" maxlength="50" required></label>
      <div class="perm-grid"><?php foreach ($grantable as $code => $label): ?><label class="check"><input type="checkbox" name="perms[]" value="<?= e($code) ?>"> <?= e($label) ?></label><?php endforeach; ?></div>
      <button class="btn sm">ساخت نقش</button></form>
  </details>
  <p class="small muted mt">مجوزهای «فقط مدیرکل» (<?= e(implode('، ', array_map(fn ($c) => $perms[$c], $super_only))) ?>) به هیچ نقش دیگری داده نمی‌شوند. هیچ نقشی — حتی مدیرکل — مجوز خواندن پیام یا فایل کاربران را ندارد؛ چنین امکانی در سامانه وجود ندارد.</p>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
