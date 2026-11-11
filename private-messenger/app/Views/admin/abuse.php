<?php use function PM\Support\e; use PM\Support\Fa; use PM\Moderation\ReportService as R; $title = 'گزارش‌های تخلف'; include __DIR__ . '/_top.php'; ?>
<h1>گزارش‌های تخلف</h1>
<p class="small muted">کاربران از صفحه پروفایل شخص، گروه یا کانال گزارش می‌دهند. هر گزارش فقط دلیل و توضیح گزارش‌دهنده را دارد؛ پیام‌ها رمزنگاری سرتاسری‌اند و برای مدیر قابل مشاهده نیستند.</p>
<div class="row"><?php foreach (['open' => 'باز', 'reviewed' => 'بررسی‌شده', 'dismissed' => 'رد شده', 'all' => 'همه'] as $k => $l): ?>
  <a class="btn sm <?= $status === $k ? '' : 'secondary' ?>" href="<?= e($base) ?>/abuse?status=<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?></div>
<div class="panel table-wrap mt"><table>
  <tr><th>زمان</th><th>گزارش‌دهنده</th><th>درباره</th><th>دلیل</th><th>توضیح</th><th>وضعیت</th><th></th></tr>
  <?php foreach ($list as $r): ?><tr>
    <td><?= e(Fa::dateTime($r['created_at'])) ?></td><td class="ltr">@<?= e($r['reporter']) ?></td>
    <td><?php if ($r['target_type'] === 'user'): ?><a href="<?= e($base) ?>/users/<?= (int)$r['target_id'] ?>">کاربر <span class="ltr">@<?= e((string)$r['target_user']) ?></span></a>
        <?php else: ?><a href="<?= e($base) ?>/conversations/<?= (int)$r['target_id'] ?>"><?= $r['is_channel'] ? 'کانال' : 'گروه' ?> #<?= e(Fa::digits((int)$r['target_id'])) ?></a><?php endif; ?></td>
    <td><?= e(R::REASONS[$r['reason']] ?? $r['reason']) ?></td><td class="small"><?= e((string)$r['details']) ?></td>
    <td><span class="tag <?= $r['status'] === 'open' ? 'warn' : 'ok' ?>"><?= e(['open' => 'باز', 'reviewed' => 'بررسی‌شده', 'dismissed' => 'رد شده'][$r['status']]) ?></span><?= $r['admin'] ? ' <span class="small ltr muted">' . e($r['admin']) . '</span>' : '' ?></td>
    <td class="row"><?php foreach (['reviewed' => 'بررسی شد', 'dismissed' => 'رد'] as $k => $l): if ($r['status'] !== $k): ?>
      <form method="post" action="<?= e($base) ?>/abuse/<?= (int)$r['id'] ?>" class="inline"><?php include __DIR__ . '/../_csrf.php'; ?><input type="hidden" name="status" value="<?= e($k) ?>"><button class="btn secondary sm"><?= e($l) ?></button></form>
    <?php endif; endforeach; ?></td></tr>
  <?php endforeach; if (!$list): ?><tr><td colspan="7" class="muted">گزارشی نیست.</td></tr><?php endif; ?></table></div>
<?php include __DIR__ . '/_bottom.php'; ?>
