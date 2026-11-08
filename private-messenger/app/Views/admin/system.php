<?php use function PM\Support\e; use PM\Support\Fa; $title = 'سیستم'; include __DIR__ . '/_top.php'; ?>
<h1>سیستم و قابلیت‌های میزبان</h1>
<div class="panel">
  <h2>دستور Cron (cPanel ← Cron Jobs)</h2>
  <div class="secret"><?= e($cron) ?></div>
  <p class="small muted">کارها: پاک‌سازی کدهای OTP قدیمی، شمارنده‌های محدودیت نرخ و نشست‌های منقضی. مسیر php را با مسیر هاست خود تطبیق دهید.</p>
</div>
<div class="panel table-wrap">
  <h2>آخرین اجراهای Cron</h2>
  <table><tr><th>کار</th><th>منبع</th><th>شروع</th><th>وضعیت</th><th>آمار</th></tr>
  <?php foreach ($runs as $r): ?><tr><td class="ltr"><?= e($r['job']) ?></td><td><?= e($r['trigger_source']) ?></td><td><?= e(Fa::dateTime($r['started_at'])) ?></td>
    <td><span class="tag <?= $r['status'] === 'ok' ? 'ok' : 'warn' ?>"><?= e($r['status']) ?></span></td><td class="ltr small"><?= e((string)$r['stats']) ?></td></tr><?php endforeach; ?>
  <?php if (!$runs): ?><tr><td colspan="5" class="muted">هنوز اجرا نشده است.</td></tr><?php endif; ?></table>
</div>
<div class="panel table-wrap">
  <h2>نتیجه بررسی هاست در زمان نصب</h2>
  <table><?php foreach ($checks as $c): ?><tr><td><?= e($c['name']) ?></td><td class="ltr"><?= e($c['value']) ?></td><td><span class="tag <?= e($c['status']) ?>"><?= e($c['status']) ?></span></td><td class="small muted"><?= e($c['note']) ?></td></tr><?php endforeach; ?></table>
</div>
<?php include __DIR__ . '/_bottom.php'; ?>
