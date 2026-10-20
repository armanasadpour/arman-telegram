<?php
declare(strict_types=1);

/**
 * Waiting-room display (queue.php?t=TOKEN): current number, waiting numbers and a rough wait.
 * No names, phones or services are shown. The page reloads itself every 20 seconds.
 */

require __DIR__ . '/core.php';
require_schema();

$token = (string) ($_GET['t'] ?? '');
$c = preg_match('/^[a-f0-9]{32}$/', $token)
    ? query('SELECT cl.id,cl.name,cl.default_duration,d.name doctor FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id WHERE cl.queue_token=?', [$token])->fetch()
    : null;
if (!$c) {
    http_response_code(404);
    layout_start('نمایشگر صف');
    echo '<p class="card">نشانی نمایشگر صف معتبر نیست.</p>';
    layout_end();
    exit();
}
$state = queue_state((int) $c['id'], date('Y-m-d'));
header('Cache-Control: no-store');
header('Refresh: 20');
header('X-Robots-Tag: noindex');
layout_start('صف ' . $c['name'], ['body_class' => 'queue-display']);
?>
<section class="queue-board" aria-live="polite">
  <header>
    <h1><?= esc($c['doctor']) ?></h1>
    <p><?= esc($c['name']) ?> • <bdi><?= esc(fa_number(date('H:i'))) ?></bdi></p>
  </header>
  <div class="queue-current">
    <span>شماره در حال ویزیت</span>
    <strong><?= $state['current'] !== null ? fa_number($state['current']) : '—' ?></strong>
  </div>
  <div class="queue-next">
    <h2>شماره‌های منتظر (<?= fa_number(count($state['waiting'])) ?>)</h2>
    <ol class="queue-numbers">
      <?php foreach (array_slice($state['waiting'], 0, 24) as $i => $n): ?>
        <li><strong><?= fa_number($n) ?></strong><small>حدود <?= fa_number(($i + 1) * $state['minutes_per_visit']) ?> دقیقه</small></li>
      <?php endforeach; ?>
    </ol>
    <?php if (!$state['waiting']): ?><p class="muted">در حال حاضر کسی در صف نیست.</p><?php endif; ?>
  </div>
  <p class="muted small">زمان‌ها تقریبی است. شماره خود را هنگام پذیرش از منشی دریافت کنید.</p>
</section>
<?php
layout_end();
