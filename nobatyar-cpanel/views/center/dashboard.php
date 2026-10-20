<?php /** @var array $c @var array $stats @var int $doctors @var array $pending @var array $sub @var array $branches @var array $s */ ?>
<div class="metric-grid">
  <article class="metric"><span>نوبت‌های امروز</span><strong><?= fa_number($stats['today']) ?></strong><small>همه پزشکان مرکز</small></article>
  <article class="metric metric-future"><span>نوبت‌های آینده</span><strong><?= fa_number($stats['upcoming']) ?></strong><small>قطعی</small></article>
  <article class="metric metric-completed"><span>ویزیت‌شده</span><strong><?= fa_number($stats['completed']) ?></strong><small>۳۰ روز اخیر</small></article>
  <article class="metric metric-cancelled"><span>عدم مراجعه</span><strong><?= fa_number($stats['no_show']) ?></strong><small>۳۰ روز اخیر</small></article>
</div>
<?php if (subscriptions_on()): ?>
<article class="subscription-card">
  <div>
    <span class="eyebrow">اشتراک مرکز</span>
    <h2><?= esc($sub['label']) ?></h2>
    <p><?= $sub['until'] ? 'اعتبار تا ' . esc(jdate($sub['until'])) : 'برای فعال‌سازی امکانات مرکز، اشتراک تهیه کنید.' ?></p>
  </div>
  <div class="days-left"><strong><?= fa_number(max(0, $sub['days_left'])) ?></strong><span>روز باقی‌مانده</span></div>
</article>
<?php endif; ?>
<div class="two-column">
  <article class="card">
    <h2>وضعیت مرکز</h2>
    <dl class="facts">
      <dt>وضعیت بررسی</dt><dd><?= esc(center_status_labels()[$c['review_status']]) ?></dd>
      <dt>کد مرکز</dt><dd><bdi><?= fa_number($c['id']) ?></bdi> <small class="muted">(برای درخواست همکاری پزشکان)</small></dd>
      <dt>پزشکان همکار</dt><dd><?= fa_number($doctors) ?></dd>
      <dt>شعبه‌ها</dt><dd><?= fa_number(count($branches)) ?></dd>
    </dl>
  </article>
  <article class="card">
    <h2>درخواست‌ها و دعوت‌های باز</h2>
    <?php foreach ($pending as $p): ?>
      <p><?= esc($p['doctor_name']) ?> — <span class="badge status-held"><?= esc(link_status_labels()[$p['status']]) ?></span></p>
    <?php endforeach; ?>
    <?php if (!$pending): ?><p class="muted">موردی در انتظار پاسخ نیست.</p><?php endif; ?>
    <a href="center.php?center=<?= (int) $c['id'] ?>&amp;tab=doctors">مدیریت پزشکان ←</a>
  </article>
</div>
