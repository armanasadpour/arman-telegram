<?php
/** Range controls in the page heading: presets, a custom Jalali range and the Excel exports. @var array $range @var array $presets @var bool $canExport @var array $series @var array $cron */
$query = fn(array $extra) => 'admin.php?' . http_build_query(['tab' => 'dashboard'] + ($range['preset'] === 'custom' ? ['from' => jdate($range['from']), 'to' => jdate($range['to'])] : ['range' => $range['preset']]) + $extra);
$label = $range['from'] === $range['to'] ? jdate($range['from']) : jdate($range['from']) . ' تا ' . jdate($range['to']);
$chart = isset($_GET['chart']) && in_array($_GET['chart'], ['revenue', 'bookings', 'users'], true) ? ['chart' => $_GET['chart']] : [];
?>
<div class="dash-toolbar dash-filters" aria-label="بازه گزارش">
  <nav class="ad-seg chip-row" aria-label="بازه‌های آماده">
    <?php foreach ($presets as $key => $text): ?>
      <a class="chip<?= $range['preset'] === $key ? ' current' : '' ?>" href="admin.php?<?= esc(http_build_query(['tab' => 'dashboard', 'range' => $key] + $chart)) ?>"<?= $range['preset'] === $key ? ' aria-current="true"' : '' ?>><?= esc($text) ?></a>
    <?php endforeach; ?>
  </nav>
  <details class="ad-pop<?= $range['preset'] === 'custom' ? ' is-custom' : '' ?>">
    <summary class="ad-pop-btn"><?= icon('calendar') ?><span>بازه دلخواه</span></summary>
    <div class="ad-pop-body">
      <form method="get" class="filter-bar dash-range">
        <input type="hidden" name="tab" value="dashboard">
        <label>از تاریخ<input data-jdate name="from" value="<?= esc(jdate($range['from'])) ?>" dir="ltr" maxlength="10" placeholder="۱۴۰۵/۰۷/۰۱" inputmode="numeric"></label>
        <label>تا تاریخ<input data-jdate name="to" value="<?= esc(jdate($range['to'])) ?>" dir="ltr" maxlength="10" placeholder="۱۴۰۵/۰۷/۳۰" inputmode="numeric"></label>
        <button><?= icon('filter') ?> نمایش</button>
      </form>
      <?php if ($canExport): ?>
        <div class="ad-pop-exports">
          <a class="button secondary" href="<?= esc($query(['export' => 'xlsx'])) ?>"><?= icon('download') ?> اکسل روند</a>
          <a class="button secondary" href="<?= esc($query(['export' => 'geo'])) ?>"><?= icon('download') ?> اکسل استان‌ها</a>
        </div>
      <?php endif; ?>
    </div>
  </details>
  <p class="ad-status <?= $cron['ok'] ? 'ok' : 'warn' ?>"><i aria-hidden="true"></i><?= admin_can('system') ? '<a href="admin.php?tab=cron">' . esc($cron['text']) . '</a>' : esc($cron['text']) ?></p>
  <p class="ad-range-label"><?= icon('clock') ?><bdi><?= esc($label) ?></bdi> • <?= $series['monthly'] ? 'نمودارها ماهانه (ماه شمسی)' : 'نمودارها روزانه' ?></p>
</div>
