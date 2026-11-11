<?php use function PM\Support\e; use PM\Support\Fa; use PM\Admin\AdminService as A; use PM\Moderation\PolicyService as P;
$word = $c['is_channel'] ? 'کانال' : 'گروه'; $title = $word . ' #' . $c['id']; include __DIR__ . '/_top.php';
$banned = $c['status'] === 'banned'; ?>
<h1><?= e($word) ?> #<?= e(Fa::digits((int)$c['id'])) ?> <?= $banned ? '<span class="tag fail">مسدود</span>' : '<span class="tag ok">فعال</span>' ?></h1>
<div class="alert small">نام <?= e($word) ?>، پیام‌ها، عکس‌ها و ویدیوها رمزنگاری سرتاسری دارند و برای مدیر قابل مشاهده نیستند.</div>
<div class="stats">
  <div class="stat"><span class="small muted">اعضا</span><b><?= e(Fa::digits(count($c['members']))) ?></b></div>
  <div class="stat"><span class="small muted">پیام/رویداد</span><b><?= e(Fa::digits((int)$c['stats']['messages'])) ?></b><span class="small"><?= e(Fa::digits((int)$c['stats']['senders'])) ?> فرستنده</span></div>
  <div class="stat"><span class="small muted">فایل‌ها روی سرور</span><b><?= e(Fa::digits((int)$c['files']['n'])) ?></b><span class="small"><?= e(Fa::bytes((int)$c['files']['bytes'])) ?> (رمزنگاری‌شده)</span></div>
  <div class="stat"><span class="small muted">ساخته‌شده / آخرین پیام</span><b class="small"><?= e(Fa::dateTime($c['created_at'])) ?></b><span class="small"><?= e(Fa::dateTime($c['stats']['last_message'])) ?></span></div>
</div>
<?php if (A::can($admin, 'conversations.manage')): ?>
<div class="panel">
  <h2>مسدودسازی</h2>
  <?php if ($banned): ?>
    <p>مسدود <?= $c['banned_until'] ? 'تا ' . e(Fa::dateTime($c['banned_until'])) : 'دائمی' ?><?= $c['ban_reason'] ? ' — ' . e($c['ban_reason']) : '' ?>. هیچ عضوی نمی‌تواند در آن پیام بفرستد و اعضا پیام «مسدود شده» را می‌بینند.</p>
    <form method="post" action="<?= e($base) ?>/conversations/<?= (int)$c['id'] ?>/unban"><?php include __DIR__ . '/../_csrf.php'; ?><button class="btn secondary">رفع مسدودیت</button></form>
  <?php else: ?>
    <form method="post" action="<?= e($base) ?>/conversations/<?= (int)$c['id'] ?>/ban" class="grid2"><?php include __DIR__ . '/../_csrf.php'; ?>
      <label class="field"><span>مدت</span><select name="duration"><?php foreach (P::DURATIONS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>دلیل (به اعضا نمایش داده می‌شود)</span><input type="text" name="reason" maxlength="500"></label>
      <div><button class="btn danger">مسدود کردن این <?= e($word) ?></button></div>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if (A::can($admin, 'services.manage')): ?>
<div class="panel"><h2>محدودیت سرویس‌ها فقط در این <?= e($word) ?></h2>
  <?php $action = '/conversations/' . (int)$c['id'] . '/services'; $keys = P::CONVERSATION_SERVICES; $current = $policies; include __DIR__ . '/_services_form.php'; ?></div>
<?php endif; ?>
<div class="panel table-wrap"><h2>اعضا</h2><table>
  <tr><th>کاربر</th><th>نقش</th><th>عضویت از</th><th></th></tr>
  <?php foreach ($c['members'] as $m): ?><tr>
    <td><a href="<?= e($base) ?>/users/<?= (int)$m['id'] ?>"><?= e($m['display_name']) ?></a> <span class="ltr small muted">@<?= e($m['username']) ?></span></td>
    <td><?= e(['owner' => 'سازنده', 'admin' => 'مدیر', 'member' => 'عضو'][$m['role']] ?? $m['role']) ?></td><td><?= e(Fa::dateTime($m['joined_at'])) ?></td>
    <td><?php if (A::can($admin, 'conversations.manage')): ?><form method="post" action="<?= e($base) ?>/conversations/<?= (int)$c['id'] ?>/remove-member" class="inline"
     ><?php include __DIR__ . '/../_csrf.php'; ?><input type="hidden" name="user" value="<?= (int)$m['id'] ?>"><button class="btn danger sm">حذف از <?= e($word) ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></table>
  <p class="small muted">حذف عضو روی سرور: دستگاه بقیه اعضا دیگر برای او رمزنگاری نمی‌کنند و او هم نمی‌تواند پیام بفرستد.</p></div>
<div class="panel table-wrap"><h2>گزارش‌های تخلف درباره این <?= e($word) ?></h2><table>
  <tr><th>زمان</th><th>گزارش‌دهنده</th><th>دلیل</th><th>توضیح</th><th>وضعیت</th></tr>
  <?php foreach ($reports as $r): ?><tr><td><?= e(Fa::dateTime($r['created_at'])) ?></td><td class="ltr">@<?= e($r['reporter']) ?></td>
    <td><?= e(\PM\Moderation\ReportService::REASONS[$r['reason']] ?? $r['reason']) ?></td><td class="small"><?= e((string)$r['details']) ?></td><td><?= e($r['status']) ?></td></tr>
  <?php endforeach; if (!$reports): ?><tr><td colspan="5" class="muted">گزارشی نیست.</td></tr><?php endif; ?></table></div>
<?php include __DIR__ . '/_bottom.php'; ?>
