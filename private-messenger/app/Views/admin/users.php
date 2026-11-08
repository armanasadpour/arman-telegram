<?php use function PM\Support\e; use PM\Support\Fa; use PM\Users\UserService; use PM\Admin\AdminService as A; $title = 'کاربران'; include __DIR__ . '/_top.php'; ?>
<div class="row"><h1 class="grow">کاربران</h1><?php if (A::can($admin, 'users.create')): ?><a class="btn" href="<?= e($base) ?>/users/new">کاربر جدید</a><?php endif; ?></div>
<form method="get" action="<?= e($base) ?>/users" class="panel row">
  <input type="search" name="q" value="<?= e($q) ?>" placeholder="جست‌وجوی نام، نام کاربری یا شماره" class="grow">
  <select name="status"><option value="">همه (به‌جز حذف‌شده)</option>
    <?php foreach (UserService::STATUSES as $k => $v): ?><option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select>
  <button class="btn secondary">فیلتر</button>
</form>
<div class="panel table-wrap"><table>
  <tr><th>نام</th><th>نام کاربری</th><th>وضعیت</th><th>نشست</th><th>آخرین فعالیت</th></tr>
  <?php foreach ($list as $u): ?>
  <tr><td><a href="<?= e($base) ?>/users/<?= (int)$u['id'] ?>"><?= e($u['display_name']) ?></a><?= $u['permanent_file_retention'] ? ' <span class="tag">نگهداری دائمی</span>' : '' ?></td>
      <td class="ltr"><?= e($u['username']) ?></td>
      <td><span class="tag <?= e($u['status']) ?>"><?= e(UserService::STATUSES[$u['status']]) ?></span></td>
      <td><?= e(Fa::digits($u['sessions'])) ?></td>
      <td><?= e(Fa::dateTime($u['last_seen_at'])) ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$list): ?><tr><td colspan="5" class="muted">کاربری یافت نشد.</td></tr><?php endif; ?>
</table></div>
<?php include __DIR__ . '/_bottom.php'; ?>
