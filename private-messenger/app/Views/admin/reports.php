<?php use function PM\Support\e; use PM\Support\Fa; use PM\Admin\Stats; $title = 'گزارش‌ها'; include __DIR__ . '/_top.php';
$n = fn ($v) => e(Fa::digits(number_format((int)$v))); ?>
<h1>گزارش‌های آماری</h1>
<div class="alert">گزارش‌ها فقط از فراداده ساخته می‌شوند: تعداد ردیف‌ها، زمان‌ها و حجم‌ها. هیچ گزارشی محتوای پیام، نام گروه، نام فایل یا گیرنده مشخص یک پیام را نشان نمی‌دهد. مشاهده و خروجی گرفتن از این صفحه در گزارش ممیزی ثبت می‌شود.</div>
<div class="row">
  <?php foreach ([7 => '۷ روز', 30 => '۳۰ روز', 90 => '۹۰ روز', 365 => 'یک سال'] as $d => $l): ?>
    <a class="btn sm <?= $days === $d ? '' : 'secondary' ?>" href="<?= e($base) ?>/reports?days=<?= $d ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
  <a class="btn sm secondary" href="<?= e($base) ?>/reports/export?days=<?= $days ?>">⬇ خروجی CSV</a>
</div>
<div class="stats mt">
  <?php foreach (['messages', 'senders', 'new_users', 'logins', 'login_failed', 'calls', 'call_minutes', 'uploads', 'upload_mb'] as $k): ?>
    <div class="stat"><span class="small muted"><?= e(Stats::METRICS[$k]) ?></span><b><?= $n($tot[$k]) ?></b></div>
  <?php endforeach; ?>
</div>
<div class="panel table-wrap">
  <table><tr><th>روز (UTC)</th><?php foreach (Stats::METRICS as $l): ?><th class="small"><?= e($l) ?></th><?php endforeach; ?></tr>
  <?php foreach ($rows as $r): ?><tr><td class="ltr"><?= e($r['day']) ?></td><?php foreach (array_keys(Stats::METRICS) as $k): ?><td><?= $n($r[$k]) ?></td><?php endforeach; ?></tr><?php endforeach; ?></table>
</div>
<p class="small muted">«ورود ناموفق» از نسخه ۰٫۷ شمرده می‌شود. «کاربران فرستنده» در جمع کل، تعداد کاربران یکتا در کل بازه است.</p>
<?php include __DIR__ . '/_bottom.php'; ?>
