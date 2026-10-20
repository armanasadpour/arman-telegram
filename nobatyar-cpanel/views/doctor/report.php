<?php
/**
 * 1.0.0-rc.20 — doctor performance report (Pro «گزارش عملکرد و آمار بازدید پروفایل»), last 30 days.
 * @var array $r doctor_report() @var int $id
 */
$pct = fn(int $a, int $b) => $b ? (int) round($a * 100 / $b) : 0;
$max = max(1, ...array_values($r['views_by_day'] ?: [0]));
?>
<p class="muted">از <bdi><?= esc(jdate($r['from'])) ?></bdi> تا <bdi><?= esc(jdate($r['to'])) ?></bdi> (۳۰ روز اخیر)</p>
<div class="metrics report-metrics">
  <article class="metric"><span>نوبت‌ها</span><strong><?= fa_number($r['total']) ?></strong><small>قطعی، انجام‌شده، عدم مراجعه و لغو</small></article>
  <article class="metric metric-completed"><span>انجام‌شده</span><strong><?= fa_number($r['completed']) ?></strong><small><?= fa_number($pct($r['completed'], $r['total'])) ?>٪ کل نوبت‌ها</small></article>
  <article class="metric metric-cancelled"><span>عدم مراجعه</span><strong><?= fa_number($r['no_show_rate']) ?>٪</strong><small><?= fa_number($r['no_show']) ?> نوبت از مراجعه‌های گذشته</small></article>
  <article class="metric"><span>لغو</span><strong><?= fa_number($r['cancelled']) ?></strong><small>توسط بیمار، شما یا منشی</small></article>
</div>
<div class="two-column">
  <article class="card">
    <h2><?= icon('eye') ?> بازدید پروفایل</h2>
    <p class="report-big"><strong><?= fa_number($r['views']) ?></strong> بازدید • <strong><?= fa_number($r['online']) ?></strong> رزرو آنلاین • تبدیل <strong><?= fa_number($r['conversion']) ?>٪</strong></p>
    <?php if ($r['views_by_day']): ?>
      <div class="mini-bars" aria-label="بازدید روزانه">
        <?php foreach ($r['views_by_day'] as $day => $n): ?>
          <span title="<?= esc(jdate((string) $day) . ': ' . fa_number((int) $n)) ?>" style="height:<?= max(4, (int) round((int) $n * 100 / $max)) ?>%"></span>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">هنوز بازدیدی ثبت نشده است. لینک یا QR صفحه خود را به بیماران بدهید.</p>
    <?php endif; ?>
  </article>
  <article class="card">
    <h2><?= icon('clock') ?> ساعت‌های پرتقاضا</h2>
    <?php if ($r['hours']): ?>
      <ol class="report-hours">
        <?php foreach ($r['hours'] as $h): ?><li><bdi><?= fa_number(sprintf('%02d:00', (int) $h['h'])) ?></bdi> تا <bdi><?= fa_number(sprintf('%02d:00', ((int) $h['h'] + 1) % 24)) ?></bdi> — <?= fa_number((int) $h['n']) ?> نوبت</li><?php endforeach; ?>
      </ol>
    <?php else: ?>
      <p class="muted">در این بازه نوبتی ثبت نشده است.</p>
    <?php endif; ?>
    <p class="muted small">نوبت‌های قطعی آینده در این بازه: <?= fa_number($r['upcoming']) ?></p>
  </article>
</div>
