<?php use function PM\Support\e; use PM\Support\Fa; $title = 'مدیران'; include __DIR__ . '/_top.php';
$byRole = []; foreach ($role_perms as $rp) { $byRole[$rp['role_id']][] = $rp['code']; } ?>
<h1>مدیران و نقش‌ها</h1>
<?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
<div class="panel table-wrap"><table>
  <tr><th>نام کاربری</th><th>نقش</th><th>TOTP</th><th>آخرین ورود</th><th>وضعیت</th><th></th></tr>
  <?php foreach ($list as $a): ?>
  <tr><td class="ltr"><?= e($a['username']) ?></td><td><?= e($a['role_name']) ?></td>
      <td><?= $a['totp_enabled'] ? '<span class="tag ok">فعال</span>' : '<span class="tag warn">غیرفعال</span>' ?></td>
      <td><?= e(Fa::dateTime($a['last_login_at'])) ?></td>
      <td><span class="tag <?= $a['status'] === 'active' ? 'ok' : 'fail' ?>"><?= $a['status'] === 'active' ? 'فعال' : 'غیرفعال' ?></span></td>
      <td><?php if ((int)$a['id'] !== (int)$admin['id']): ?><form method="post" action="<?= e($base) ?>/admins/<?= (int)$a['id'] ?>/status" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?>
        <input type="hidden" name="status" value="<?= $a['status'] === 'active' ? 'disabled' : 'active' ?>">
        <button class="btn sm <?= $a['status'] === 'active' ? 'danger' : 'secondary' ?>"><?= $a['status'] === 'active' ? 'غیرفعال‌سازی' : 'فعال‌سازی' ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
</table></div>
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
<div class="panel small">
  <h2>مجوزهای نقش‌ها</h2>
  <?php foreach ($roles as $r): ?>
    <p><b><?= e($r['name']) ?>:</b> <?= $r['is_super'] ? 'همه مجوزها' : e(implode('، ', array_map(fn ($c) => $perms[$c] ?? $c, $byRole[$r['id']] ?? []))) ?></p>
  <?php endforeach; ?>
  <p class="muted">هیچ نقشی — حتی مدیرکل — مجوز خواندن پیام یا فایل کاربران را ندارد؛ چنین امکانی در سامانه وجود ندارد.</p>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
