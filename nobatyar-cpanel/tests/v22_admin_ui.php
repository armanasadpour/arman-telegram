<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.10 — administration dashboard helpers: previous-period range, change badges, area and ring
 * charts, sparkline, relative time and initials. Read-only against the disposable nobatyar_test_http database.
 */

if (PHP_SAPI !== 'cli' || getenv('NOBATYAR_TEST') !== '1') {
    exit("Set NOBATYAR_TEST=1 to run disposable tests.\n");
}
require __DIR__ . '/../core.php';
if (config()['database'] !== 'nobatyar_test_http') {
    exit(9);
}
require __DIR__ . '/../lib/admin_dashboard.php';

$checks = 0;
function check($ok, string $name): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException('FAIL ' . $name);
    }
    $checks++;
    echo "PASS $name\n";
}

// Previous period of the same length, ending the day before.
check(dashboard_previous_range('2026-10-01', '2026-10-30') === ['from' => '2026-09-01', 'to' => '2026-09-30', 'days' => 30], 'previous 30-day period');
check(dashboard_previous_range('2026-10-18', '2026-10-18') === ['from' => '2026-10-17', 'to' => '2026-10-17', 'days' => 1], 'previous day for "today"');
check(dashboard_previous_range('2026-03-21', '2026-03-27')['to'] === '2026-03-20', 'previous week crosses Nowruz');

// Change badges.
check(dashboard_delta(150, 100) === ['dir' => 'up', 'text' => '۵۰٪'], 'increase of 50%');
check(dashboard_delta(75, 100) === ['dir' => 'down', 'text' => '۲۵٪'], 'decrease of 25%');
check(dashboard_delta(5, 0)['dir'] === 'new' && dashboard_delta(0, 0)['dir'] === 'none', 'zero previous period is "new" or "none"');
check(dashboard_delta(100, 100)['dir'] === 'flat', 'no change');
check(dashboard_delta(1235, 100)['text'] === '۱٬۱۳۵٪', 'large change grouped with Persian digits');
check(dashboard_delta(1013, 1000)['text'] === '۱.۳٪', 'one decimal for small changes');

// Period totals use the same definitions as the counters.
$today = date('Y-m-d');
$totals = dashboard_period_totals($today, $today);
$counts = dashboard_counts($today, $today);
check($totals['bookings'] === $counts['bookings_range'] && $totals['patients'] === $counts['patients_new'] && $totals['doctors'] === $counts['doctors_new'], 'period totals match the counters');
check($totals['revenue'] === dashboard_revenue($today, $today)['total'], 'period revenue matches the revenue summary');

// Area chart: one smooth line and fill per series, one tooltip target per period, no NaN.
$buckets = dashboard_series(date('Y-m-d', strtotime('-6 days')), $today)['buckets'];
$area = svg_chart('area', $buckets, [['key' => 'doctors', 'label' => 'پزشک', 'class' => 's1'], ['key' => 'patients', 'label' => 'بیمار', 'class' => 's2']], 'count', 'آزمون', ['width' => 760, 'height' => 270]);
check(str_contains($area, 'class="chart" data-kind="area"') && str_contains($area, 'viewBox="0 0 760 270"'), 'area chart keeps the chart class and custom size');
check(substr_count($area, 'class="area ') === 2 && substr_count($area, 'class="line ') === 2 && substr_count($area, '<linearGradient') === 2, 'area chart draws a fill and a line per series');
check(substr_count($area, 'data-tip=') === 7 && !str_contains($area, 'NAN') && !str_contains($area, 'INF'), 'area chart has a tooltip per period');
$area2 = svg_chart('area', $buckets, [['key' => 'doctors', 'label' => 'پزشک', 'class' => 's1']], 'count', 'دوم');
preg_match_all('/id="(ag[0-9]+-[0-9]+)"/', $area . $area2, $ids);
check(count($ids[1]) === count(array_unique($ids[1])), 'gradient ids are unique per chart');
$single = svg_chart('area', [['label' => 'x', 'short' => 'x', 'a' => 3]], [['key' => 'a', 'label' => 'a', 'class' => 's1']], 'count', 'تک');
check(str_contains($single, '<svg') && !str_contains($single, 'NAN'), 'single-period area chart renders');
preg_match_all('/ d="([^"]+)"/', $area, $paths);
$ys = [];
foreach ($paths[1] as $d) {
    preg_match_all('/-?[0-9.]+/', $d, $nums);
    foreach ($nums[0] as $i => $v) {
        if ($i % 2 === 1) {
            $ys[] = (float) $v;
        }
    }
}
check($ys && min($ys) >= 14 && max($ys) <= 270 - 34, 'smoothed curve stays inside the plot area');

// Ring chart.
$donut = svg_donut([['label' => 'الف', 'value' => 3, 'class' => 's1'], ['label' => 'ب', 'value' => 1, 'class' => 's2'], ['label' => 'ج', 'value' => 0, 'class' => 's3']], 'حلقه');
check(substr_count($donut, 'class="donut-seg') === 2 && str_contains($donut, '(۷۵٪)') && str_contains($donut, '(۲۵٪)'), 'ring chart skips empty parts and shows shares');
check(str_contains(svg_donut([['label' => 'الف', 'value' => 0, 'class' => 's1']], 'خالی'), 'donut-track') && !str_contains(svg_donut([['label' => 'الف', 'value' => 0, 'class' => 's1']], 'خالی'), 'donut-seg'), 'empty ring chart shows only the track');

// Sparkline, relative time, initials.
check(svg_sparkline([1], 's1') === '' && substr_count(svg_sparkline([0, 2, 4], 's1'), '<polyline') === 1, 'sparkline needs two points');
check(time_ago(date('Y-m-d H:i:s', time() - 30)) === 'لحظاتی پیش' && time_ago(date('Y-m-d H:i:s', time() - 300)) === '۵ دقیقه پیش', 'relative time in minutes');
check(time_ago(date('Y-m-d H:i:s', time() - 7200)) === '۲ ساعت پیش' && time_ago(date('Y-m-d H:i:s', time() - 30 * 86400)) === jdate(date('Y-m-d H:i:s', time() - 30 * 86400)), 'relative time in hours, then the Jalali date');
check(name_initials('دکتر سارا احمدی') === 'دس' && name_initials('  ') === '•' && name_initials('admin@example.test') === 'a', 'initials for avatars');

echo "SUCCESS $checks rc.10 dashboard checks\n";
