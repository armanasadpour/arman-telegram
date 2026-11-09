<?php use function PM\Support\e; use PM\Support\Fa; use PM\Support\Phone; use PM\Users\UserService; use PM\Admin\AdminService as A;
$title = $u['display_name']; include __DIR__ . '/_top.php';
$locked = $u['locked_until'] !== null && strtotime($u['locked_until'] . ' UTC') > time(); ?>
<h1><?= e($u['display_name']) ?> <span class="tag <?= e($u['status']) ?>"><?= e(UserService::STATUSES[$u['status']]) ?></span></h1>

<?php if ($temp): ?>
<div class="panel">
  <div class="alert warn"><b>رمز موقت — فقط همین یک بار نمایش داده می‌شود.</b> آن را از کانال امن به کاربر بدهید؛ در اولین ورود باید تغییر کند.</div>
  <div class="secret"><?= e($temp) ?></div>
  <p class="small muted">نام کاربری: <span class="ltr"><?= e($u['username']) ?></span></p>
</div>
<?php endif; ?>

<div class="panel">
  <h2>اطلاعات حساب</h2>
  <?php if (A::can($admin, 'users.edit') && $u['status'] !== 'deleted'): ?>
  <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/edit">
    <?php include __DIR__ . '/../_csrf.php'; ?>
    <div class="grid2">
      <label class="field"><span>نام کاربری</span><input type="text" name="username" class="ltr" value="<?= e($u['username']) ?>" required></label>
      <label class="field"><span>نام نمایشی</span><input type="text" name="display_name" value="<?= e($u['display_name']) ?>" required></label>
      <label class="field"><span>شماره موبایل</span><input type="tel" name="phone" class="ltr" value="<?= e($u['phone_e164']) ?>"></label>
    </div>
    <button class="btn secondary">ذخیره</button>
  </form>
  <?php else: ?>
  <p>نام کاربری: <span class="ltr"><?= e($u['username']) ?></span> — موبایل: <span class="ltr"><?= e(Phone::mask($u['phone_e164'])) ?></span></p>
  <?php endif; ?>
  <?php if ($method !== 'password' && !$u['phone_e164']): ?><div class="alert warn">روش ورود فعلی به پیامک نیاز دارد اما برای این کاربر شماره موبایل ثبت نشده است.</div><?php endif; ?>
  <p class="small muted">ایجاد: <?= e(Fa::dateTime($u['created_at'])) ?> — آخرین فعالیت: <?= e(Fa::dateTime($u['last_seen_at'])) ?>
    <?= (int)$u['must_change_password'] === 1 ? ' — <span class="tag warn">باید رمز موقت را تغییر دهد</span>' : '' ?></p>
</div>

<?php if ($u['status'] !== 'deleted'): ?>
<div class="panel">
  <h2>وضعیت و دسترسی</h2>
  <div class="row">
    <?php foreach (['active' => ['users.activate', 'فعال‌سازی', 'btn'], 'suspended' => ['users.suspend', 'تعلیق', 'btn secondary'], 'blocked' => ['users.block', 'مسدودسازی', 'btn secondary']] as $st => [$perm, $label, $cls]):
        if ($u['status'] !== $st && A::can($admin, $perm)): ?>
      <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/status" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?>
        <input type="hidden" name="status" value="<?= e($st) ?>"><button class="<?= e($cls) ?>"><?= e($label) ?></button></form>
    <?php endif; endforeach; ?>
    <?php if (A::can($admin, 'users.sessions.revoke')): ?>
      <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/password" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary">ساخت رمز موقت جدید</button></form>
    <?php endif; ?>
    <?php if ($locked && A::can($admin, 'users.edit')): ?>
      <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/unlock" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary">برداشتن قفل ورود</button></form>
    <?php endif; ?>
  </div>
  <?php if ($locked): ?><p class="small"><span class="tag warn">ورود موقتاً قفل است تا <?= e(Fa::dateTime($u['locked_until'])) ?></span></p><?php endif; ?>
  <p class="small muted">تعلیق، مسدودسازی و ساخت رمز موقت جدید، همه نشست‌های فعال کاربر را باطل می‌کند.</p>
  <?php if (A::can($admin, 'users.delete')): ?>
  <details><summary>حذف حساب</summary>
    <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/status" class="mt"><?php include __DIR__ . '/../_csrf.php'; ?>
      <input type="hidden" name="status" value="deleted">
      <label class="field"><span>برای تأیید، کلمه «حذف» را بنویسید</span><input type="text" name="confirm" required></label>
      <button class="btn danger">حذف حساب</button></form>
  </details>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>نگهداری فایل‌ها</h2>
  <p>نگهداری دائمی فایل‌های این حساب: <b><?= $u['permanent_file_retention'] ? 'فعال' : 'غیرفعال' ?></b></p>
  <p class="small muted"><?= $u['permanent_file_retention']
      ? 'فایل‌های آپلودشده توسط این حساب تا لغو این گزینه به‌صورت خودکار حذف نمی‌شوند.'
      : 'فایل‌ها ۷ روز پس از آپلود به‌صورت خودکار و غیرقابل بازیابی از سرور حذف می‌شوند.' ?>
    <?php if ($u['retention_changed_at']): ?> آخرین تغییر: <?= e(Fa::dateTime($u['retention_changed_at'])) ?> توسط <span class="ltr"><?= e((string)$retention_by) ?></span>.<?php endif; ?>
    با لغو نگهداری دائمی، مهلت ۷ روزه از <b>زمان لغو</b> محاسبه می‌شود. (بارگذاری فایل در فاز ۴ فعال می‌شود.)</p>
  <?php if (A::can($admin, 'users.retention.manage')): ?>
  <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/retention"><?php include __DIR__ . '/../_csrf.php'; ?>
    <input type="hidden" name="permanent" value="<?= $u['permanent_file_retention'] ? '0' : '1' ?>">
    <button class="btn secondary"><?= $u['permanent_file_retention'] ? 'لغو نگهداری دائمی' : 'فعال‌سازی نگهداری دائمی' ?></button></form>
  <?php else: ?><p class="small muted">فقط مدیرکل می‌تواند این گزینه را تغییر دهد.</p><?php endif; ?>
</div>

<div class="panel">
  <h2>نشست‌ها</h2>
  <div class="table-wrap"><table>
    <tr><th>دستگاه</th><th>IP (کوتاه‌شده)</th><th class="hide-sm">ورود</th><th>آخرین فعالیت</th><th></th></tr>
    <?php foreach ($sessions as $s): ?>
    <tr><td><?= e($s['user_agent_summary']) ?></td><td class="ltr"><?= e($s['ip_display']) ?></td>
        <td class="hide-sm"><?= e(Fa::dateTime($s['created_at'])) ?></td><td><?= e(Fa::dateTime($s['last_used_at'])) ?></td>
        <td><?php if (A::can($admin, 'users.sessions.revoke')): ?><form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/sessions/revoke" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?>
          <input type="hidden" name="session" value="<?= e($s['public_id']) ?>"><button class="btn danger sm">خروج اجباری</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$sessions): ?><tr><td colspan="5" class="muted">نشست فعالی وجود ندارد.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($sessions && A::can($admin, 'users.sessions.revoke')): ?>
  <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/sessions/revoke-all" class="mt"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn danger">خروج اجباری از همه نشست‌ها</button></form>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>دستگاه‌های رمزنگاری</h2>
  <p class="small muted">کلیدهای خصوصی فقط روی دستگاه کاربر هستند؛ اینجا فقط اثر انگشت کلید عمومی نمایش داده می‌شود و هیچ دسترسی به پیام‌ها نمی‌دهد.</p>
  <div class="table-wrap"><table>
    <tr><th>دستگاه</th><th>وضعیت</th><th class="hide-sm">اثر انگشت کلید</th><th class="hide-sm">آخرین فعالیت</th><th></th></tr>
    <?php foreach ($devices as $d): ?>
    <tr><td><?= e($d['label']) ?></td>
        <td><span class="tag <?= $d['status'] === 'active' ? 'ok' : 'fail' ?>"><?= $d['status'] === 'active' ? 'فعال' : 'لغوشده' ?></span></td>
        <td class="ltr small hide-sm"><?= $d['identity_key'] ? e(\PM\E2ee\KeyService::fingerprint($d['identity_key'])) : '—' ?></td>
        <td class="hide-sm"><?= e(Fa::dateTime($d['last_seen_at'])) ?></td>
        <td><?php if ($d['status'] === 'active' && A::can($admin, 'users.devices.revoke')): ?><form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/devices/revoke" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?>
          <input type="hidden" name="device" value="<?= e($d['public_id']) ?>"><button class="btn danger sm">لغو</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$devices): ?><tr><td colspan="5" class="muted">کاربر هنوز وارد برنامه نشده و کلیدی نساخته است.</td></tr><?php endif; ?>
  </table></div>
  <?php if ($devices && A::can($admin, 'users.identity.reset')): ?>
  <details class="mt"><summary>بازنشانی هویت رمزنگاری (برای دستگاه گم‌شده)</summary>
    <div class="alert warn small">همه دستگاه‌های کاربر از کار می‌افتند و کاربر در ورود بعدی کلید جدید می‌سازد. پیام‌های قبلی به هیچ وجه قابل بازیابی نیستند (حتی برای مدیر) و همه مخاطبان هشدار «کلید امنیتی تغییر کرد» می‌بینند.</div>
    <form method="post" action="<?= e($base) ?>/users/<?= (int)$u['id'] ?>/identity-reset"><?php include __DIR__ . '/../_csrf.php'; ?>
      <label class="field"><span>برای تأیید، کلمه «بازنشانی» را بنویسید</span><input type="text" name="confirm" required></label>
      <button class="btn danger">بازنشانی هویت</button></form>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($audit): ?>
<div class="panel">
  <h2>سوابق مدیریتی این حساب</h2>
  <div class="table-wrap"><table>
    <tr><th>زمان</th><th>اقدام</th><th>انجام‌دهنده</th></tr>
    <?php foreach ($audit as $a): ?><tr><td><?= e(Fa::dateTime($a['created_at'])) ?></td><td class="ltr"><?= e($a['action']) ?></td><td class="ltr"><?= e($a['actor'] ?? $a['actor_type']) ?></td></tr><?php endforeach; ?>
  </table></div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_bottom.php'; ?>
