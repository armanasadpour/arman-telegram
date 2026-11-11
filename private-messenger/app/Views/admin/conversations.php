<?php use function PM\Support\e; use PM\Support\Fa; $title = 'گروه‌ها و کانال‌ها'; include __DIR__ . '/_top.php'; ?>
<h1>گروه‌ها و کانال‌ها</h1>
<div class="alert">نام، پیام‌ها، عکس‌ها و ویدیوهای گروه‌ها و کانال‌ها رمزنگاری سرتاسری دارند و فقط روی دستگاه اعضا باز می‌شوند. این صفحه فقط اطلاعات غیرمحتوایی را نشان می‌دهد: اعضا، زمان‌ها، تعداد و وضعیت.</div>
<form method="get" action="<?= e($base) ?>/conversations" class="panel row">
  <select name="kind" class="grow"><?php foreach (['all' => 'همه', 'group' => 'گروه‌ها', 'channel' => 'کانال‌ها'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <select name="status" class="grow"><option value="all">همه وضعیت‌ها</option><option value="banned" <?= $status === 'banned' ? 'selected' : '' ?>>مسدودشده</option></select>
  <input type="search" name="member" class="ltr grow" placeholder="نام کاربری یک عضو" value="<?= e($member) ?>">
  <button class="btn sm">فیلتر</button>
</form>
<div class="panel table-wrap"><table>
  <tr><th>شناسه</th><th>نوع</th><th>سازنده</th><th>اعضا</th><th>پیام‌ها</th><th>آخرین فعالیت</th><th>گزارش</th><th>وضعیت</th></tr>
  <?php foreach ($list as $c): ?>
  <tr><td><a href="<?= e($base) ?>/conversations/<?= (int)$c['id'] ?>">#<?= e(Fa::digits((int)$c['id'])) ?></a></td>
    <td><?= $c['is_channel'] ? 'کانال' : 'گروه' ?></td><td class="ltr">@<?= e((string)$c['creator']) ?></td>
    <td><?= e(Fa::digits((int)$c['members'])) ?></td><td><?= e(Fa::digits((int)$c['messages'])) ?></td><td><?= e(Fa::dateTime($c['last_activity_at'])) ?></td>
    <td><?= (int)$c['reports'] ? '<span class="tag warn">' . e(Fa::digits((int)$c['reports'])) . '</span>' : '—' ?></td>
    <td><?= $c['status'] === 'banned' ? '<span class="tag fail">مسدود</span>' : '<span class="tag ok">فعال</span>' ?></td></tr>
  <?php endforeach; if (!$list): ?><tr><td colspan="8" class="muted">موردی نیست.</td></tr><?php endif; ?>
</table></div>
<?php include __DIR__ . '/_bottom.php'; ?>
