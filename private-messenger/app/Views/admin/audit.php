<?php use function PM\Support\e; use PM\Support\Fa; use PM\Support\Ip; $title = 'گزارش ممیزی'; include __DIR__ . '/_top.php'; ?>
<h1>گزارش ممیزی</h1>
<div class="alert <?= $verify['ok'] ? 'ok' : 'error' ?>">
  <?= $verify['ok'] ? 'زنجیره هش سالم است' : 'زنجیره هش در رکورد ' . e(Fa::digits((int)$verify['broken_at'])) . ' شکسته است — احتمال دستکاری مستقیم دیتابیس' ?>
  (<?= e(Fa::digits($verify['checked'])) ?> رکورد). هش سر زنجیره: <span class="ltr small"><?= e(substr($verify['head'], 0, 16)) ?>…</span>
</div>
<div class="panel table-wrap"><table>
  <tr><th>زمان</th><th>انجام‌دهنده</th><th>اقدام</th><th>هدف</th><th>جزئیات</th><th>IP</th></tr>
  <?php foreach ($rows as $r): ?>
  <tr><td><?= e(Fa::dateTime($r['created_at'])) ?></td><td class="ltr"><?= e($r['actor'] ?? $r['actor_type']) ?></td><td class="ltr"><?= e($r['action']) ?></td>
      <td class="ltr"><?= e($r['target_user'] ?? ($r['target_type'] ? $r['target_type'] . '#' . $r['target_id'] : '')) ?></td>
      <td class="ltr small"><?= e((string)$r['details']) ?></td><td class="ltr small"><?= e(Ip::fromBinary($r['ip'])) ?></td></tr>
  <?php endforeach; ?>
</table></div>
<?php include __DIR__ . '/_bottom.php'; ?>
