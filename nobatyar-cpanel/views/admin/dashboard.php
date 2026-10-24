<?php
/**
 * Administrator dashboard (1.0.0-rc.10 layout): headline cards with the change against the previous period,
 * one tabbed analytics chart, booking status ring, revenue summary, work waiting for review, activity feed,
 * latest transactions, provinces and cities, expiring subscriptions and pending requests.
 * @var array $range @var array $presets @var array $counts @var array $revenue @var array $series @var array $geo @var array $expiring
 * @var array $pending @var int $tickets @var bool $canExport @var bool $canFinance @var array $compare @var array $previous
 * @var ?array $activity @var ?array $transactions @var int $reviewsPending @var array $cron
 */
$n = fn($v) => fa_number(number_format((int) $v, 0, '.', '٬'));
$buckets = $series['buckets'];
$cur = $compare['current'];
$prev = $compare['previous'];
$prevLabel = 'نسبت به ' . fa_number($previous['days']) . ' روز قبل';
$delta = function (string $key) use ($cur, $prev): string {
    $d = dashboard_delta((int) $cur[$key], (int) $prev[$key]);
    $icon = ['up' => 'arrow-up', 'down' => 'arrow-down'][$d['dir']] ?? null;
    return '<span class="ad-delta ' . $d['dir'] . '">' . ($icon ? icon($icon) : '') . esc($d['text']) . '</span>';
};
$col = fn(string $key) => array_map(fn($b) => (int) $b[$key], $buckets);
$sum = fn(string $key) => array_sum($col($key));
$link = fn(string $perm, string $href, string $inner, string $class) => admin_can($perm)
    ? '<a class="' . $class . '" href="' . esc($href) . '">' . $inner . '</a>'
    : '<article class="' . $class . '">' . $inner . '</article>';

// Analytics tabs: revenue only for finance roles.
$tabs = [];
if ($canFinance) {
    $tabs['revenue'] = ['label' => 'درآمد', 'title' => 'روند درآمد', 'total' => toman($revenue['range']['total']), 'note' => 'درآمد در بازه انتخابی', 'delta' => 'revenue',
        'series' => [['key' => 'subscription', 'label' => 'اشتراک', 'class' => 's1'], ['key' => 'fee', 'label' => 'کارمزد رزرو', 'class' => 's2']], 'unit' => 'toman'];
}
$tabs['bookings'] = ['label' => 'نوبت‌ها', 'title' => 'روند نوبت‌ها', 'total' => $n($sum('bookings')), 'note' => 'نوبت ثبت‌شده در بازه (بر اساس زمان ثبت)', 'delta' => null,
    'series' => [['key' => 'bookings', 'label' => 'نوبت ثبت‌شده', 'class' => 's1'], ['key' => 'cancelled', 'label' => 'لغوشده', 'class' => 's2']], 'unit' => 'count'];
$tabs['users'] = ['label' => 'کاربران', 'title' => 'رشد کاربران', 'total' => $n($sum('patients') + $sum('doctors')), 'note' => 'کاربر جدید در بازه', 'delta' => 'patients',
    'series' => [['key' => 'doctors', 'label' => 'پزشک جدید', 'class' => 's1'], ['key' => 'patients', 'label' => 'بیمار جدید', 'class' => 's2']], 'unit' => 'count'];
$active = isset($_GET['chart'], $tabs[$_GET['chart']]) ? (string) $_GET['chart'] : array_key_first($tabs);
$rangeQuery = $range['preset'] === 'custom' ? ['from' => jdate($range['from']), 'to' => jdate($range['to'])] : ['range' => $range['preset']];

// Booking status mix (by visit time, same definitions as the counters).
$upcoming = max(0, $counts['bookings_range'] - $counts['completed_range'] - $counts['no_show_range']);
$mix = [
    ['label' => 'انجام‌شده', 'value' => $counts['completed_range'], 'class' => 's3'],
    ['label' => 'در انتظار مراجعه', 'value' => $upcoming, 'class' => 's1'],
    ['label' => 'عدم مراجعه', 'value' => $counts['no_show_range'], 'class' => 's4'],
    ['label' => 'لغوشده', 'value' => $counts['cancelled_range'], 'class' => 's2'],
];
$mixTotal = array_sum(array_column($mix, 'value'));
$attended = $counts['completed_range'] + $counts['no_show_range'];

// Work waiting for an administrator.
$expiringTotal = $expiring['doctors_total'] + $expiring['centers_total'];
$todo = [
    ['perm' => 'doctors', 'href' => 'admin.php?tab=doctors&status=pending', 'icon' => 'doctor', 'title' => 'پزشکان در انتظار تأیید', 'sub' => 'بررسی مدارک و نظام پزشکی', 'n' => $counts['doctors_pending']],
    ['perm' => 'centers', 'href' => 'admin.php?tab=centers&status=pending', 'icon' => 'building', 'title' => 'مراکز در انتظار تأیید', 'sub' => 'مجوز و اطلاعات مرکز درمانی', 'n' => $counts['centers_pending']],
    ['perm' => 'finance', 'href' => 'admin.php?tab=payments', 'icon' => 'refresh', 'title' => 'استرداد در انتظار', 'sub' => $counts['refunds_pending'] ? toman($counts['refunds_pending_toman']) : 'بازگشت وجه بیماران', 'n' => $counts['refunds_pending'], 'hide' => !$canFinance],
    ['perm' => 'finance', 'href' => 'admin.php?tab=subscriptions', 'icon' => 'alert', 'title' => 'اشتراک نزدیک انقضا', 'sub' => '۱۴ روز آینده: ' . $n($expiring['doctors_total']) . ' پزشک، ' . $n($expiring['centers_total']) . ' مرکز', 'n' => $expiringTotal, 'hide' => !subscriptions_on()],
    ['perm' => 'support', 'href' => 'admin.php?tab=support', 'icon' => 'chat', 'title' => 'تیکت‌های باز', 'sub' => 'در انتظار پاسخ یا بررسی', 'n' => $tickets],
    ['perm' => 'content', 'href' => 'admin.php?tab=reviews&status=pending', 'icon' => 'star', 'title' => 'نظرات در انتظار انتشار', 'sub' => 'نظر بیماران پس از تأیید منتشر می‌شود', 'n' => $reviewsPending, 'hide' => !admin_can('content')],
];
// 1.0.0-rc.16: a site without active specialties (search by specialty does not work; doctors type theirs in).
if (!query('SELECT 1 FROM specialties WHERE active=1 AND deleted_at IS NULL LIMIT 1')->fetch()) {
    $todo[] = ['perm' => 'catalog', 'href' => 'admin.php?tab=specialties', 'icon' => 'alert', 'title' => 'هیچ تخصصی تعریف نشده', 'sub' => 'فهرست پیشنهادی را با یک کلیک اضافه کنید', 'n' => 1];
}
$todo = array_values(array_filter($todo, fn($t) => empty($t['hide'])));
$todoOpen = array_sum(array_column($todo, 'n'));
$txStatus = fn(string $s) => ['verified' => 'tx-verified', 'failed' => 'tx-failed', 'cancelled' => 'tx-cancelled', 'refund_due' => 'tx-refund_due', 'refunded' => 'tx-refunded', 'redirected' => 'tx-redirected'][$s] ?? '';
?>
<?php if (admin_can('doctors')): /* 1.0.28 «new doctor» popup, filled by admin.js */ ?>
<div class="nd-overlay" data-new-doctors data-csrf="<?= esc(csrf()) ?>" hidden>
  <section class="nd-modal" role="dialog" aria-modal="true" aria-labelledby="nd-title">
    <button type="button" class="nd-close icon-button" data-nd-close aria-label="بستن"><?= icon('x') ?></button>
    <div class="nd-head">
      <span class="nd-bell"><?= icon('bell') ?><b data-nd-count></b></span>
      <h2 id="nd-title" data-nd-title>پزشک جدید ثبت‌نام کرد</h2>
      <p>درخواست عضویت برای بررسی و تأیید شما ثبت شده است.</p>
    </div>
    <ul class="nd-list" data-nd-list></ul>
    <div class="nd-actions">
      <a class="button" data-nd-review href="admin.php?tab=doctors&amp;status=pending"><?= icon('doctor') ?> بررسی درخواست‌ها</a>
      <button type="button" class="secondary" data-nd-seen>متوجه شدم</button>
    </div>
  </section>
</div>
<?php endif; ?>
<div class="dash">

<section class="ad-kpis" aria-label="شاخص‌های اصلی">
  <?php if ($canFinance): ?>
    <?= $link('finance', 'admin.php?tab=payments&status=verified',
        '<header><span>درآمد بازه انتخابی</span><i class="ad-chip c-blue">' . icon('wallet') . '</i></header>' .
        '<div class="ad-kpi-num"><strong class="ad-kpi-value" data-count="' . (int) $revenue['range']['total'] . '">' . $n($revenue['range']['total']) . '</strong><em class="ad-unit">تومان</em></div>' .
        '<footer>' . $delta('revenue') . '<small>' . esc($prevLabel) . '</small></footer>' . svg_sparkline(array_map(fn($b) => $b['subscription'] + $b['fee'], $buckets), 's1'),
        'ad-kpi accent') ?>
  <?php else: ?>
    <article class="ad-kpi">
      <header><span>نوبت‌های امروز</span><i class="ad-chip c-blue"><?= icon('calendar') ?></i></header>
      <div class="ad-kpi-num"><strong class="ad-kpi-value" data-count="<?= (int) $counts['bookings_today'] ?>"><?= $n($counts['bookings_today']) ?></strong></div>
      <footer><small><?= $n($counts['bookings_month']) ?> نوبت در ماه جاری</small></footer>
    </article>
  <?php endif; ?>
  <article class="ad-kpi">
    <header><span>نوبت‌های بازه</span><i class="ad-chip c-green"><?= icon('calendar-check') ?></i></header>
    <div class="ad-kpi-num"><strong class="ad-kpi-value" data-count="<?= (int) $counts['bookings_range'] ?>"><?= $n($counts['bookings_range']) ?></strong></div>
    <footer><?= $delta('bookings') ?><small><?= $n($counts['completed_range']) ?> انجام‌شده • <?= $n($counts['no_show_range']) ?> عدم مراجعه</small></footer>
  </article>
  <article class="ad-kpi">
    <header><span>بیماران جدید</span><i class="ad-chip c-violet"><?= icon('users') ?></i></header>
    <div class="ad-kpi-num"><strong class="ad-kpi-value" data-count="<?= (int) $counts['patients_new'] ?>"><?= $n($counts['patients_new']) ?></strong></div>
    <footer><?= $delta('patients') ?><small>از <?= $n($counts['patients_total']) ?> بیمار</small></footer>
    <?= svg_sparkline($col('patients'), 's5') ?>
  </article>
  <?= $link('doctors', 'admin.php?tab=doctors&subscription=active',
      '<header><span>پزشکان فعال</span><i class="ad-chip c-amber">' . icon('doctor') . '</i></header>' .
      '<div class="ad-kpi-num"><strong class="ad-kpi-value" data-count="' . (int) $counts['doctors_active'] . '">' . $n($counts['doctors_active']) . '</strong></div>' .
      '<footer>' . $delta('doctors') . '<small>' . $n($counts['doctors_new']) . ' پزشک جدید • تأییدشده ' . $n($counts['doctors_approved']) . '</small></footer>',
      'ad-kpi') ?>
</section>

<section class="ad-strip" aria-label="آمار تکمیلی">
  <div><?= icon('doctor') ?><span>پزشکان ثبت‌نام‌شده</span><b><?= $n($counts['doctors_total']) ?></b></div>
  <div><?= icon('users') ?><span>بیماران</span><b><?= $n($counts['patients_total']) ?></b></div>
  <div><?= icon('building') ?><span>مطب‌ها و مراکز</span><b><?= $n($counts['clinics_total']) ?></b><small><?= $n($counts['centers_approved']) ?> مرکز</small></div>
  <div><?= icon('calendar') ?><span>نوبت‌های امروز</span><b><?= $n($counts['bookings_today']) ?></b><small><?= $n($counts['bookings_month']) ?> در ماه</small></div>
  <?php if ($canFinance): ?><div><?= icon('card') ?><span>تراکنش موفق / ناموفق</span><b><?= $n($counts['tx_success']) ?> <small>/ <?= $n($counts['tx_failed']) ?></small></b></div><?php endif; ?>
  <div><?= icon('x') ?><span>لغوشده در بازه</span><b><?= $n($counts['cancelled_range']) ?></b></div>
</section>

<div class="ad-grid">
  <section class="panel ad-card ad-span-8 ad-analytics" aria-label="تحلیل عملکرد">
    <div class="panel-head">
      <h2>تحلیل عملکرد</h2>
      <nav class="ad-tabs" role="tablist" aria-label="نوع نمودار">
        <?php foreach ($tabs as $key => $t): ?>
          <a role="tab" href="admin.php?<?= esc(http_build_query(['tab' => 'dashboard'] + $rangeQuery + ['chart' => $key])) ?>" data-pane="<?= $key ?>" aria-controls="pane-<?= $key ?>" aria-selected="<?= $key === $active ? 'true' : 'false' ?>"<?= $key === $active ? ' class="current"' : '' ?>><?= esc($t['label']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
    <?php foreach ($tabs as $key => $t): ?>
      <div class="ad-pane chart-panel" id="pane-<?= $key ?>" role="tabpanel"<?= $key === $active ? '' : ' hidden' ?>>
        <div class="ad-pane-head">
          <div>
            <h3><?= esc($t['title']) ?></h3>
            <p class="ad-big"><?= esc($t['total']) ?></p>
            <p class="ad-note"><?= esc($t['note']) ?><?= $t['delta'] ? ' ' . $delta($t['delta']) : '' ?></p>
          </div>
          <ul class="legend"><?php foreach ($t['series'] as $s): ?><li><i class="swatch <?= $s['class'] ?>"></i><?= esc($s['label']) ?></li><?php endforeach; ?></ul>
        </div>
        <div class="chart-box"><?= svg_chart('area', $buckets, $t['series'], $t['unit'], $t['title'], ['width' => 760, 'height' => 270]) ?></div>
        <details class="chart-table"><summary>نمایش جدول</summary>
          <div class="table-wrap"><table><thead><tr><th>دوره</th><?php foreach ($t['series'] as $s): ?><th><?= esc($s['label']) ?></th><?php endforeach; ?><?php if ($key === 'revenue'): ?><th>جمع</th><?php endif; ?></tr></thead><tbody>
            <?php foreach ($buckets as $b): ?><tr><td><?= esc($b['label']) ?></td><?php foreach ($t['series'] as $s): ?><td><?= $t['unit'] === 'toman' ? toman($b[$s['key']]) : $n($b[$s['key']]) ?></td><?php endforeach; ?><?php if ($key === 'revenue'): ?><td><?= toman($b['subscription'] + $b['fee']) ?></td><?php endif; ?></tr><?php endforeach; ?>
          </tbody></table></div>
        </details>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="panel ad-card ad-span-4 ad-mix">
    <div class="panel-head"><h2>وضعیت نوبت‌ها</h2><span class="muted small">بر اساس زمان نوبت</span></div>
    <div class="ad-donut">
      <?= svg_donut($mix, 'وضعیت نوبت‌های بازه') ?>
      <div class="ad-donut-center"><strong><?= $n($mixTotal) ?></strong><span>نوبت در بازه</span></div>
    </div>
    <ul class="ad-legend">
      <?php foreach ($mix as $m): ?>
        <li><i class="swatch <?= $m['class'] ?>"></i><span><?= esc($m['label']) ?></span><b><?= $n($m['value']) ?></b><small><?= $mixTotal ? fa_number((string) round(100 * $m['value'] / $mixTotal)) . '٪' : '—' ?></small></li>
      <?php endforeach; ?>
    </ul>
    <p class="ad-foot-note"><?= icon('check') ?>نرخ حضور: <b><?= $attended ? fa_number((string) round(100 * $counts['completed_range'] / $attended)) . '٪' : '—' ?></b> <span class="muted">(انجام‌شده از نوبت‌های گذشته)</span></p>
  </section>

  <?php if ($canFinance): ?>
  <section class="panel ad-card ad-span-4 ad-revenue">
    <div class="panel-head"><h2>خلاصه درآمد</h2><span class="muted small">بدون پرداخت‌های آزمایشی</span></div>
    <ul class="ad-rev-list">
      <?php foreach (['today' => 'درآمد امروز', 'month' => 'درآمد ماه جاری', 'year' => 'درآمد سال جاری', 'range' => 'درآمد بازه انتخابی'] as $key => $title): $r = $revenue[$key]; $share = $r['total'] ? round(100 * $r['subscription'] / $r['total']) : 0; ?>
        <li class="revenue-card<?= $key === 'range' ? ' accent' : '' ?>">
          <div class="ad-rev-top"><span><?= esc($title) ?></span><strong><?= toman($r['total']) ?></strong></div>
          <div class="ad-split" title="اشتراک <?= esc(fa_number((string) $share)) ?>٪"><i class="s1" style="--w:<?= $r['total'] ? $share : 0 ?>%"></i><i class="s2" style="--w:<?= $r['total'] ? 100 - $share : 0 ?>%"></i></div>
          <dl>
            <div><dt><i class="swatch s1"></i>اشتراک</dt><dd><?= toman($r['subscription']) ?></dd></div>
            <div><dt><i class="swatch s2"></i>کارمزد رزرو</dt><dd><?= toman($r['fee']) ?></dd></div>
          </dl>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <section class="panel ad-card ad-span-4 ad-todo">
    <div class="panel-head"><h2>نیازمند رسیدگی</h2><span class="ad-pill <?= $todoOpen ? 'warn' : 'ok' ?>"><i aria-hidden="true"></i><?= $todoOpen ? $n($todoOpen) . ' مورد' : 'همه‌چیز مرتب است' ?></span></div>
    <ul class="ad-todo-list">
      <?php foreach ($todo as $t): $inner = '<i class="ad-chip ' . ($t['n'] ? 'c-amber' : 'c-muted') . '">' . icon($t['n'] ? $t['icon'] : 'check') . '</i><span><strong>' . esc($t['title']) . '</strong><small>' . esc($t['sub']) . '</small></span><b class="ad-count' . ($t['n'] ? ' on' : '') . '">' . $n($t['n']) . '</b>'; ?>
        <li class="<?= $t['n'] ? 'attention' : 'is-clear' ?>"><?= admin_can($t['perm']) ? '<a href="' . esc($t['href']) . '">' . $inner . '</a>' : '<div>' . $inner . '</div>' ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($activity !== null): ?>
  <section class="panel ad-card ad-span-4 ad-activity">
    <div class="panel-head"><h2>فعالیت‌های اخیر</h2><span class="ad-pill live"><i aria-hidden="true"></i>گزارش فعالیت</span></div>
    <?php if (!$activity): ?>
      <p class="empty-state">هنوز رویدادی ثبت نشده است.</p>
    <?php else: ?>
      <ol class="ad-feed">
        <?php foreach ($activity as $e): $who = $e['actor_admin_id'] ? ((string) ($e['admin_name'] ?: $e['admin_email']) ?: 'مدیر') : ($e['actor_user_id'] ? 'کاربر سایت' : 'سامانه'); ?>
          <li><i class="ad-chip <?= str_starts_with((string) $e['action'], 'security') || str_contains((string) $e['action'], 'failed') ? 'c-red' : ($e['actor_admin_id'] ? 'c-blue' : 'c-green') ?>"><?= icon($e['actor_admin_id'] ? 'shield' : 'user') ?></i>
            <span><strong><?= esc(audit_label((string) $e['action'])) ?></strong><small><?= esc($who) ?> • <?= esc(time_ago((string) $e['created_at'])) ?></small></span></li>
        <?php endforeach; ?>
      </ol>
      <a class="ad-more" href="admin.php?tab=audit">مشاهده همه رویدادها<?= icon('arrow-left') ?></a>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($transactions !== null): ?>
  <section class="panel ad-card ad-span-8 ad-tx">
    <div class="panel-head"><h2>آخرین تراکنش‌ها</h2><a class="ad-more" href="admin.php?tab=payments">همه تراکنش‌ها<?= icon('arrow-left') ?></a></div>
    <?php if (!$transactions): ?>
      <p class="empty-state">هنوز تراکنشی ثبت نشده است.</p>
    <?php else: ?>
      <div class="table-wrap ad-table"><table>
        <thead><tr><th>پرداخت‌کننده</th><th>نوع</th><th>درگاه</th><th>مبلغ</th><th>وضعیت</th><th>زمان</th></tr></thead>
        <tbody>
        <?php foreach ($transactions as $t): $payer = $t['doctor'] ?: ($t['mobile'] ? admin_mobile((string) $t['mobile']) : 'مهمان'); ?>
          <tr>
            <td><span class="ad-person"><span class="ad-avatar sm" aria-hidden="true"><?= esc($t['doctor'] ? name_initials((string) $t['doctor']) : '#') ?></span><span><bdi><?= esc($payer) ?></bdi><small>شناسه <?= $n($t['id']) ?></small></span></span></td>
            <td><?= esc(payment_purpose_label((string) $t['purpose'])) ?></td>
            <td><?= esc(payment_gateways()[$t['gateway']]['label'] ?? (string) $t['gateway']) ?><?= $t['test_mode'] ? ' <span class="badge">آزمایشی</span>' : '' ?></td>
            <td class="nowrap"><?= toman($t['amount_toman']) ?></td>
            <td><span class="badge <?= $txStatus((string) $t['status']) ?>"><?= esc(payment_status_label((string) $t['status'])) ?></span></td>
            <td class="muted nowrap"><?= esc(time_ago((string) $t['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="panel ad-card ad-span-4 ad-cities">
    <div class="panel-head"><h2>شهرهای پرنوبت</h2><span class="muted small">در بازه انتخابی</span></div>
    <?php if (!$geo['cities']): ?>
      <p class="empty-state">در این بازه نوبتی ثبت نشده است.</p>
    <?php else: $topCity = max(1, (int) $geo['cities'][0]['bookings']); ?>
      <ol class="rank-list ad-rank">
        <?php foreach ($geo['cities'] as $i => $c): ?><li><b class="ad-rank-n"><?= $n($i + 1) ?></b><span><?= esc($c['city']) ?> <small><?= esc($c['province']) ?></small><i class="ad-bar" style="--w:<?= round(100 * $c['bookings'] / $topCity) ?>%"></i></span><strong><?= $n($c['bookings']) ?></strong></li><?php endforeach; ?>
      </ol>
    <?php endif; ?>
  </section>

  <section class="panel ad-card ad-span-8 ad-geo">
    <div class="panel-head"><h2>آمار استان‌ها</h2><span class="muted small">نوبت‌ها در بازه انتخابی</span></div>
    <?php $shown = 0; $maxBookings = max(1, ...array_map(fn($r) => (int) $r['bookings'], $geo['provinces'] ?: [['bookings' => 1]])); ?>
    <div class="table-wrap ad-table">
      <table class="geo-table">
        <thead><tr><th>استان</th><th>پزشک</th><th>مطب</th><th>نوبت</th><?php if ($canFinance): ?><th>کارمزد</th><th>اشتراک</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($geo['provinces'] as $r): if (!$r['doctors'] && !$r['bookings'] && !$r['clinics']) continue; $shown++; ?>
          <tr>
            <td><?= esc($r['name']) ?></td>
            <td><?= $n($r['doctors']) ?></td>
            <td><?= $n($r['clinics']) ?></td>
            <td><span class="inline-bar" style="--w:<?= round(100 * $r['bookings'] / $maxBookings) ?>%"><?= $n($r['bookings']) ?></span></td>
            <?php if ($canFinance): ?><td class="nowrap"><?= toman($r['fee']) ?></td><td class="nowrap"><?= toman($r['subscription']) ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (!$shown): ?><p class="empty-state">هنوز در هیچ استانی پزشک یا نوبتی ثبت نشده است.</p><?php endif; ?>
  </section>

  <section class="panel ad-card ad-span-4 ad-list-card">
    <div class="panel-head"><h2>درخواست‌های در انتظار تأیید</h2><?php if (admin_can('doctors')): ?><a class="ad-more" href="admin.php?tab=doctors&amp;status=pending">همه<?= icon('arrow-left') ?></a><?php endif; ?></div>
    <?php if (!$pending): ?>
      <p class="empty-state">درخواست تازه‌ای برای بررسی نیست.</p>
    <?php else: ?>
      <ul class="ad-people">
        <?php foreach ($pending as $d): ?>
          <li><span class="ad-avatar sm" aria-hidden="true"><?= esc(name_initials((string) $d['name'])) ?></span>
            <span><?= admin_can('doctors') ? '<a href="admin.php?tab=doctors&amp;doctor=' . (int) $d['id'] . '">' . esc($d['name']) . '</a>' : esc($d['name']) ?><small><?= esc($d['specialty'] ?? '') ?> • <?= esc(jdate($d['created_at'])) ?></small></span>
            <span class="badge status-pending">در انتظار</span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <?php if (subscriptions_on()): ?>
  <section class="panel ad-card ad-span-12 ad-list-card">
    <div class="panel-head"><h2>اشتراک‌های نزدیک انقضا</h2><?php if ($canFinance): ?><a class="ad-more" href="admin.php?tab=subscriptions">مدیریت اشتراک‌ها<?= icon('arrow-left') ?></a><?php endif; ?></div>
    <?php if (!$expiring['doctors'] && !$expiring['centers']): ?>
      <p class="empty-state">در ۱۴ روز آینده اشتراکی تمام نمی‌شود.</p>
    <?php else: ?>
      <ul class="ad-people two-col">
        <?php foreach ([['doctors', 'پزشک', 'doctor'], ['centers', 'مرکز', 'center']] as [$k, $kind, $param]): foreach ($expiring[$k] as $d): $days = (int) $d['days_left']; ?>
          <li><span class="ad-avatar sm" aria-hidden="true"><?= esc(name_initials((string) $d['name'])) ?></span>
            <span><?= admin_can($k) ? '<a href="admin.php?tab=' . $k . '&amp;' . $param . '=' . (int) $d['id'] . '">' . esc($d['name']) . '</a>' : esc($d['name']) ?><small><?= $kind ?> • پایان <?= esc(jdate($d['subscription_until'])) ?></small></span>
            <span class="badge <?= $days <= 3 ? 'status-cancelled' : 'status-pending' ?>"><?= $days ? $n($days) . ' روز مانده' : 'امروز' ?></span></li>
        <?php endforeach; endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
</div>
<div class="chart-tip" data-chart-tip hidden role="status"></div>
