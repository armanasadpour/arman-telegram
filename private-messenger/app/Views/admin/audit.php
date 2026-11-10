<?php use function PM\Support\e; use PM\Support\Fa; use PM\Support\Ip; $title = 'گزارش ممیزی'; include __DIR__ . '/_top.php'; ?>
<h1>گزارش ممیزی</h1>
<div class="alert <?= $verify['ok'] ? 'ok' : 'error' ?>">
  <?= $verify['ok'] ? 'زنجیره هش سالم است' : 'زنجیره هش در رکورد ' . e(Fa::digits((int)$verify['broken_at'])) . ' شکسته است — احتمال دستکاری مستقیم دیتابیس' ?>
  (<?= e(Fa::digits($verify['checked'])) ?> رکورد). هش سر زنجیره: <span class="ltr small"><?= e(substr($verify['head'], 0, 16)) ?>…</span>
</div>
<form method="get" action="<?= e($base) ?>/audit" class="panel grid2">
  <label class="field"><span>اقدام (یا ابتدای آن، مثلاً user.)</span><input type="text" name="action" class="ltr" list="actions" value="<?= e($f['action']) ?>"></label>
  <datalist id="actions"><?php foreach ($actions as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist>
  <label class="field"><span>نام کاربری مدیر انجام‌دهنده</span><input type="text" name="actor" class="ltr" value="<?= e($f['actor']) ?>"></label>
  <label class="field"><span>نام کاربری کاربر هدف</span><input type="text" name="target" class="ltr" value="<?= e($f['target']) ?>"></label>
  <div class="row"><label class="field grow"><span>از تاریخ (میلادی)</span><input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="field grow"><span>تا تاریخ</span><input type="date" name="to" value="<?= e($f['to']) ?>"></label></div>
  <div class="row"><button class="btn">فیلتر</button><a class="btn secondary" href="<?= e($base) ?>/audit">همه</a>
    <a class="btn secondary" href="<?= e($base) ?>/audit/export?<?= e(http_build_query(array_filter($f))) ?>">⬇ خروجی CSV</a></div>
</form>
<div class="panel table-wrap"><table>
  <tr><th>زمان</th><th>انجام‌دهنده</th><th>اقدام</th><th>هدف</th><th>جزئیات</th><th>IP</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= e(Fa::dateTime($r['created_at'])) ?></td><td class="ltr"><?= e($r['actor'] ?? $r['actor_type']) ?></td><td class="ltr"><?= e($r['action']) ?></td>
      <td class="ltr"><?= e($r['target_user'] ?? ($r['target_type'] ? $r['target_type'] . '#' . $r['target_id'] : '')) ?></td>
      <td class="ltr small"><?= e((string)$r['details']) ?></td><td class="ltr small"><?= e(Ip::fromBinary($r['ip'])) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<div class="row">
  <?php $q = array_filter($f); if ($page > 1): ?><a class="btn secondary sm" href="<?= e($base) ?>/audit?<?= e(http_build_query($q + ['page' => $page - 1])) ?>">← جدیدتر</a><?php endif; ?>
  <?php if ($more): ?><a class="btn secondary sm" href="<?= e($base) ?>/audit?<?= e(http_build_query($q + ['page' => $page + 1])) ?>">قدیمی‌تر →</a><?php endif; ?>
</div>
<p class="small muted">هر رکورد هش رکورد قبلی را در خود دارد؛ تغییر یا حذف مستقیم یک رکورد در دیتابیس، زنجیره را می‌شکند و بالای همین صفحه اعلام می‌شود. مشاهده حساب کاربران، گزارش‌ها، خروجی‌ها و پشتیبان‌گیری هم ثبت می‌شوند.</p>
<?php include __DIR__ . '/_bottom.php'; ?>
