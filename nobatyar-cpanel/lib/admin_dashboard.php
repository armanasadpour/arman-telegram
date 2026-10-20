<?php
declare(strict_types=1);

/**
 * Business dashboard for the administrator: platform counts, revenue and growth over a Jalali date range,
 * province/city figures and the data behind the charts and Excel exports.
 *
 * Revenue definitions (Toman, real payments only — sandbox/test transactions are excluded):
 *  - subscription revenue = paid doctor subscription orders + paid center subscription orders (online or
 *    recorded offline by an administrator), by payment date;
 *  - booking-fee revenue  = verified booking-fee transactions that have not been refunded, by verification
 *    date. Visit fees are paid at the clinic and never appear here.
 */





function dashboard_presets(): array
{
    return ['today' => 'امروز', '7d' => '۷ روز اخیر', '30d' => '۳۰ روز اخیر', 'month' => 'ماه جاری', 'year' => 'سال جاری'];
}

/** Date range from ?range=<preset> or the Jalali ?from=&to= pair (default: the current Jalali month). */
function dashboard_range(): array
{
    $today = date('Y-m-d');
    $preset = (string) ($_GET['range'] ?? '');
    $ranges = [
        'today' => [$today, $today],
        '7d' => [date('Y-m-d', strtotime('-6 days')), $today],
        '30d' => [date('Y-m-d', strtotime('-29 days')), $today],
        'month' => [jalali_month_start($today), $today],
        'year' => [jalali_year_start($today), $today],
    ];
    if (isset($ranges[$preset])) {
        [$from, $to] = $ranges[$preset];
    } else {
        [$from, $to] = admin_date_range();
        $preset = isset($_GET['from']) || isset($_GET['to']) ? 'custom' : 'month';
    }
    // Long ranges are capped at five years to keep the queries cheap on shared hosting.
    if ((strtotime($to) - strtotime($from)) / 86400 > 1830) {
        $from = date('Y-m-d', strtotime($to . ' -1830 days'));
    }
    return ['from' => $from, 'to' => $to, 'preset' => $preset];
}

function dashboard_sum(string $sql, array $args): int
{
    return (int) query($sql, $args)->fetchColumn();
}

/** Revenue split for one period (inclusive dates). */
function dashboard_revenue(string $from, string $to): array
{
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $real = 'LEFT JOIN payment_transactions t ON t.id=o.payment_tx_id WHERE (t.id IS NULL OR t.test_mode=0) AND o.status=\'paid\' AND o.paid_at BETWEEN ? AND ?';
    $subscription =
        dashboard_sum("SELECT COALESCE(SUM(o.payable_toman-o.vat_toman),0) FROM subscription_orders o $real", $args) +
        dashboard_sum("SELECT COALESCE(SUM(o.payable_toman-o.vat_toman),0) FROM center_orders o $real", $args);
    $fee = dashboard_sum(
        "SELECT COALESCE(SUM(CASE WHEN status='refunded' THEN refund_penalty_toman ELSE amount_toman-vat_toman END),0) FROM payment_transactions WHERE purpose='booking' AND status IN ('verified','refunded') AND test_mode=0 AND verified_at BETWEEN ? AND ?",
        $args,
    );
    return ['subscription' => $subscription, 'fee' => $fee, 'total' => $subscription + $fee];
}

function dashboard_counts(string $from, string $to): array
{
    $c = fn(string $sql, array $args = []) => dashboard_sum($sql, $args);
    $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $today = date('Y-m-d');
    $monthStart = jalali_month_start($today);
    $booked = "b.status IN ('confirmed','completed','no_show')";
    $bookingsBetween = fn(string $a, string $b, string $cond) => $c(
        "SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE $cond AND s.starts_at BETWEEN ? AND ?",
        [$a . ' 00:00:00', $b . ' 23:59:59'],
    );
    return [
        'doctors_total' => $c('SELECT COUNT(*) FROM doctors WHERE deleted_at IS NULL'),
        'doctors_approved' => $c("SELECT COUNT(*) FROM doctors WHERE review_status='approved'"),
        'doctors_active' => $c("SELECT COUNT(*) FROM doctors WHERE review_status='approved' AND active=1" . (subscriptions_on() ? ' AND subscription_until>=CURDATE()' : '')),
        'doctors_pending' => $c("SELECT COUNT(*) FROM doctors WHERE review_status='pending'"),
        'doctors_suspended' => $c("SELECT COUNT(*) FROM doctors WHERE review_status IN ('suspended','rejected') AND deleted_at IS NULL"),
        'doctors_new' => $c('SELECT COUNT(*) FROM doctors WHERE created_at BETWEEN ? AND ?', $range),
        'patients_total' => $c("SELECT COUNT(*) FROM user_roles WHERE role='patient'"),
        'patients_new' => $c("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='patient' WHERE u.created_at BETWEEN ? AND ?", $range),
        'clinics_total' => $c('SELECT COUNT(*) FROM clinics WHERE active=1'),
        'centers_approved' => $c("SELECT COUNT(*) FROM centers WHERE review_status='approved'"),
        'centers_pending' => $c("SELECT COUNT(*) FROM centers WHERE review_status='pending'"),
        'bookings_today' => $bookingsBetween($today, $today, $booked),
        // The whole current Jalali month, including visits booked for its remaining days.
        'bookings_month' => $bookingsBetween($monthStart, jalali_month_end($today), $booked),
        'bookings_range' => $bookingsBetween($from, $to, $booked),
        'cancelled_range' => $bookingsBetween($from, $to, "b.status='cancelled'"),
        'completed_range' => $bookingsBetween($from, $to, "b.status='completed'"),
        'no_show_range' => $bookingsBetween($from, $to, "b.status='no_show'"),
        'tx_success' => $c("SELECT COUNT(*) FROM payment_transactions WHERE purpose<>'gateway_test' AND test_mode=0 AND status IN ('verified','refund_due','refunded') AND created_at BETWEEN ? AND ?", $range),
        'tx_failed' => $c("SELECT COUNT(*) FROM payment_transactions WHERE purpose<>'gateway_test' AND test_mode=0 AND status IN ('failed','cancelled') AND created_at BETWEEN ? AND ?", $range),
        'refunds_pending' => $c("SELECT COUNT(*) FROM refunds WHERE status='pending'"),
        'refunds_pending_toman' => $c("SELECT COALESCE(SUM(amount_toman),0) FROM refunds WHERE status='pending'"),
    ];
}


/**
 * Time series for the charts and the export: one bucket per day for ranges up to 62 days, otherwise one
 * bucket per Jalali month. SQL groups by calendar day; PHP folds days into Jalali months.
 */
function dashboard_series(string $from, string $to): array
{
    $monthly = (strtotime($to) - strtotime($from)) / 86400 > 61;
    $buckets = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $key = $monthly ? jalali_month_start($d) : $d;
        $buckets[$key] ??= [
            'key' => $key,
            'label' => $monthly ? jalali_month_label($d) : jdate($d),
            'short' => $monthly ? jalali_month_label($d) : fa_number(jalali_parts($d)[1] . '/' . jalali_parts($d)[2]),
            'doctors' => 0, 'patients' => 0, 'bookings' => 0, 'cancelled' => 0, 'completed' => 0,
            'subscription' => 0, 'fee' => 0,
        ];
    }
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $real = 'LEFT JOIN payment_transactions t ON t.id=o.payment_tx_id WHERE (t.id IS NULL OR t.test_mode=0) AND o.status=\'paid\' AND o.paid_at BETWEEN ? AND ?';
    $sources = [
        'doctors' => 'SELECT DATE(created_at) d,COUNT(*) n FROM doctors WHERE created_at BETWEEN ? AND ? GROUP BY d',
        'patients' => "SELECT DATE(u.created_at) d,COUNT(*) n FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='patient' WHERE u.created_at BETWEEN ? AND ? GROUP BY d",
        'bookings' => "SELECT DATE(b.created_at) d,COUNT(*) n FROM bookings b WHERE b.status IN ('confirmed','completed','no_show','cancelled') AND b.created_at BETWEEN ? AND ? GROUP BY d",
        'cancelled' => "SELECT DATE(s.starts_at) d,COUNT(*) n FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status='cancelled' AND s.starts_at BETWEEN ? AND ? GROUP BY d",
        'completed' => "SELECT DATE(s.starts_at) d,COUNT(*) n FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status='completed' AND s.starts_at BETWEEN ? AND ? GROUP BY d",
        'subscription' => "SELECT DATE(o.paid_at) d,SUM(o.payable_toman-o.vat_toman) n FROM subscription_orders o $real GROUP BY d",
        'subscription_center' => "SELECT DATE(o.paid_at) d,SUM(o.payable_toman-o.vat_toman) n FROM center_orders o $real GROUP BY d",
        'fee' => "SELECT DATE(verified_at) d,SUM(CASE WHEN status='refunded' THEN refund_penalty_toman ELSE amount_toman-vat_toman END) n FROM payment_transactions WHERE purpose='booking' AND status IN ('verified','refunded') AND test_mode=0 AND verified_at BETWEEN ? AND ? GROUP BY d",
    ];
    foreach ($sources as $field => $sql) {
        foreach (query($sql, $args)->fetchAll(PDO::FETCH_KEY_PAIR) as $day => $n) {
            $key = $monthly ? jalali_month_start($day) : $day;
            if (isset($buckets[$key])) {
                $buckets[$key][$field === 'subscription_center' ? 'subscription' : $field] += (int) $n;
            }
        }
    }
    return ['monthly' => $monthly, 'buckets' => array_values($buckets)];
}

/** Per-province figures; bookings and revenue are attributed to the clinic city (primary clinic for subscriptions). */
function dashboard_geo(string $from, string $to): array
{
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    $rows = query(
        "SELECT p.id,p.name,
            (SELECT COUNT(DISTINCT cl.doctor_id) FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN doctors d ON d.id=cl.doctor_id WHERE c.province_id=p.id AND d.review_status='approved') doctors,
            (SELECT COUNT(*) FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE c.province_id=p.id AND cl.active=1) clinics,
            (SELECT COUNT(*) FROM centers ce JOIN cities c ON c.id=ce.city_id WHERE c.province_id=p.id AND ce.review_status='approved') centers,
            (SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN cities c ON c.id=cl.city_id WHERE c.province_id=p.id AND b.status IN ('confirmed','completed','no_show') AND s.starts_at BETWEEN ? AND ?) bookings,
            (SELECT COALESCE(SUM(CASE WHEN t.status='refunded' THEN t.refund_penalty_toman ELSE t.amount_toman-t.vat_toman END),0) FROM payment_transactions t JOIN bookings b ON b.id=t.reference_id JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN cities c ON c.id=cl.city_id WHERE t.purpose='booking' AND t.status IN ('verified','refunded') AND t.test_mode=0 AND c.province_id=p.id AND t.verified_at BETWEEN ? AND ?) fee,
            (SELECT COALESCE(SUM(o.payable_toman-o.vat_toman),0) FROM subscription_orders o JOIN clinics cl ON cl.doctor_id=o.doctor_id AND cl.is_primary=1 JOIN cities c ON c.id=cl.city_id LEFT JOIN payment_transactions t ON t.id=o.payment_tx_id WHERE (t.id IS NULL OR t.test_mode=0) AND o.status='paid' AND c.province_id=p.id AND o.paid_at BETWEEN ? AND ?) subscription
         FROM provinces p ORDER BY p.sort_order,p.name",
        array_merge($args, $args, $args),
    )->fetchAll();
    $cities = query(
        "SELECT c.name city,p.name province,COUNT(*) bookings FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE b.status IN ('confirmed','completed','no_show') AND s.starts_at BETWEEN ? AND ? GROUP BY c.id,c.name,p.name ORDER BY bookings DESC LIMIT 10",
        $args,
    )->fetchAll();
    usort($rows, fn($a, $b) => [$b['bookings'], $b['doctors']] <=> [$a['bookings'], $a['doctors']]);
    return ['provinces' => $rows, 'cities' => $cities];
}

function dashboard_expiring(): array
{
    return [
        'doctors' => query(
            "SELECT id,name,subscription_until,DATEDIFF(subscription_until,CURDATE()) days_left FROM doctors WHERE review_status='approved' AND subscription_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY) ORDER BY subscription_until,id LIMIT 8",
        )->fetchAll(),
        'centers' => query(
            "SELECT id,name,subscription_until,DATEDIFF(subscription_until,CURDATE()) days_left FROM centers WHERE review_status='approved' AND subscription_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY) ORDER BY subscription_until,id LIMIT 8",
        )->fetchAll(),
        'doctors_total' => (int) query("SELECT COUNT(*) FROM doctors WHERE review_status='approved' AND subscription_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)")->fetchColumn(),
        'centers_total' => (int) query("SELECT COUNT(*) FROM centers WHERE review_status='approved' AND subscription_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 14 DAY)")->fetchColumn(),
    ];
}

function admin_dashboard_data(): array
{
    $range = dashboard_range();
    $today = date('Y-m-d');
    $previous = dashboard_previous_range($range['from'], $range['to']);
    $canFinance = admin_can('finance');
    return [
        'previous' => $previous,
        'compare' => [
            'current' => dashboard_period_totals($range['from'], $range['to']),
            'previous' => dashboard_period_totals($previous['from'], $previous['to']),
        ],
        'activity' => admin_can('system') ? dashboard_activity(6) : null,
        'transactions' => $canFinance ? dashboard_recent_transactions(6) : null,
        'reviewsPending' => admin_can('content') ? (int) query("SELECT COUNT(*) FROM reviews WHERE status='pending'")->fetchColumn() : 0,
        'cron' => dashboard_cron_status(),
        'range' => $range,
        'presets' => dashboard_presets(),
        'counts' => dashboard_counts($range['from'], $range['to']),
        'revenue' => [
            'today' => dashboard_revenue($today, $today),
            'month' => dashboard_revenue(jalali_month_start($today), $today),
            'year' => dashboard_revenue(jalali_year_start($today), $today),
            'range' => dashboard_revenue($range['from'], $range['to']),
        ],
        'series' => dashboard_series($range['from'], $range['to']),
        'geo' => dashboard_geo($range['from'], $range['to']),
        'expiring' => dashboard_expiring(),
        'pending' => query(
            "SELECT d.id,d.name,d.created_at,sp.name specialty FROM doctors d LEFT JOIN specialties sp ON sp.id=d.specialty_id WHERE d.review_status='pending' ORDER BY d.id LIMIT 6",
        )->fetchAll(),
        'tickets' => (int) query("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','waiting')")->fetchColumn(),
        'canExport' => admin_can('export'),
        'canFinance' => admin_can('finance'),
    ];
}

/* ---------- 1.0.0-rc.10: comparison with the previous period, activity feed, recent transactions ---------- */

/** The period of the same length that ends the day before $from. */
function dashboard_previous_range(string $from, string $to): array
{
    $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
    return ['from' => date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days')), 'to' => $prevTo, 'days' => $days];
}

/** Headline figures used for the trend badges; same definitions as dashboard_counts() and dashboard_revenue(). */
function dashboard_period_totals(string $from, string $to): array
{
    $range = [$from . ' 00:00:00', $to . ' 23:59:59'];
    return [
        'revenue' => dashboard_revenue($from, $to)['total'],
        'bookings' => dashboard_sum("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status IN ('confirmed','completed','no_show') AND s.starts_at BETWEEN ? AND ?", $range),
        'patients' => dashboard_sum("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role='patient' WHERE u.created_at BETWEEN ? AND ?", $range),
        'doctors' => dashboard_sum('SELECT COUNT(*) FROM doctors WHERE created_at BETWEEN ? AND ?', $range),
    ];
}

/**
 * Change against the previous period: ['dir' => up|down|flat|new|none, 'text' => Persian label].
 * "new" when the previous period was zero, "none" when both are zero.
 */
function dashboard_delta(int $current, int $previous): array
{
    if ($previous === 0) {
        return $current === 0 ? ['dir' => 'none', 'text' => 'بدون تغییر'] : ['dir' => 'new', 'text' => 'تازه'];
    }
    $pct = ($current - $previous) * 100 / $previous;
    $abs = abs($pct);
    $text = fa_number($abs >= 100 ? number_format($abs, 0, '.', '٬') : rtrim(rtrim(number_format($abs, 1, '.', ''), '0'), '.')) . '٪';
    if ($abs < 0.05) {
        return ['dir' => 'flat', 'text' => '۰٪'];
    }
    return ['dir' => $pct > 0 ? 'up' : 'down', 'text' => $text];
}

/** Latest administrator and user actions from the activity log (labels only, no details or IP). */
function dashboard_activity(int $limit): array
{
    return query(
        "SELECT l.id,l.action,l.created_at,l.actor_admin_id,l.actor_user_id,a.name admin_name,a.email admin_email FROM audit_log l LEFT JOIN admins a ON a.id=l.actor_admin_id ORDER BY l.id DESC LIMIT $limit",
    )->fetchAll();
}

function dashboard_recent_transactions(int $limit): array
{
    return query(
        "SELECT t.id,t.purpose,t.gateway,t.status,t.amount_toman,t.test_mode,t.created_at,u.mobile,d.name doctor FROM payment_transactions t LEFT JOIN users u ON u.id=t.user_id LEFT JOIN doctors d ON d.id=t.doctor_id WHERE t.purpose<>'gateway_test' ORDER BY t.id DESC LIMIT $limit",
    )->fetchAll();
}

/** Scheduled jobs health from the last cron run (every five minutes is expected). */
function dashboard_cron_status(): array
{
    $last = (string) setting('cron_last_run');
    if ($last === '') {
        return ['ok' => false, 'text' => 'Cron هنوز اجرا نشده است'];
    }
    $minutes = (int) floor((time() - strtotime($last)) / 60);
    return $minutes <= 15
        ? ['ok' => true, 'text' => 'Cron فعال • آخرین اجرا ' . time_ago($last)]
        : ['ok' => false, 'text' => 'آخرین اجرای Cron ' . time_ago($last)];
}

/** "۵ دقیقه پیش" style relative time for recent events; older than a week falls back to the Jalali date. */
function time_ago(string $at): string
{
    $s = max(0, time() - strtotime($at));
    return match (true) {
        $s < 60 => 'لحظاتی پیش',
        $s < 3600 => fa_number((int) floor($s / 60)) . ' دقیقه پیش',
        $s < 86400 => fa_number((int) floor($s / 3600)) . ' ساعت پیش',
        $s < 7 * 86400 => fa_number((int) floor($s / 86400)) . ' روز پیش',
        default => jdate($at),
    };
}

/** Initials for an avatar badge (first letter of the first two words). */
function name_initials(string $name): string
{
    $words = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice(array_values(array_filter($words, fn($w) => $w !== '')), 0, 2) as $w) {
        $out .= mb_substr($w, 0, 1);
    }
    return $out !== '' ? $out : '•';
}

/**
 * Ring chart for a few parts: ['label' => ..., 'value' => int, 'class' => 's1'..'s4'].
 * Every segment carries data-tip for the shared chart tooltip.
 */
function svg_donut(array $parts, string $title): string
{
    $total = array_sum(array_map(fn($p) => (int) $p['value'], $parts));
    $r = 52;
    $c = 2 * M_PI * $r;
    $out = '<svg class="chart donut" viewBox="0 0 140 140" role="img" aria-label="' . esc($title) . '">';
    $out .= '<circle class="donut-track" cx="70" cy="70" r="' . $r . '"/>';
    if ($total > 0) {
        $offset = 0.0;
        $gap = count(array_filter($parts, fn($p) => (int) $p['value'] > 0)) > 1 ? 3.0 : 0.0;
        foreach ($parts as $p) {
            $v = (int) $p['value'];
            if ($v <= 0) {
                continue;
            }
            $len = $c * $v / $total;
            $tip = $p['label'] . ': ' . fa_number(number_format($v, 0, '.', '٬')) . ' (' . fa_number((string) round(100 * $v / $total)) . '٪)';
            $out .= '<circle class="donut-seg ' . esc($p['class']) . '" cx="70" cy="70" r="' . $r . '" stroke-dasharray="' . round(max(0.5, $len - $gap), 2) . ' ' . round($c, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 70 70)" tabindex="0" data-tip="' . esc($tip) . '"><title>' . esc($tip) . '</title></circle>';
            $offset += $len;
        }
    }
    return $out . '</svg>';
}

/** Tiny trend line for a stat card (decorative; the figure itself is in the card text). */
function svg_sparkline(array $values, string $class): string
{
    $n = count($values);
    if ($n < 2) {
        return '';
    }
    $max = max(1, ...array_map('intval', $values));
    $pts = [];
    foreach (array_values($values) as $i => $v) {
        $pts[] = round(100 * $i / ($n - 1), 1) . ',' . round(30 - 26 * (int) $v / $max, 1);
    }
    $line = implode(' ', $pts);
    return '<svg class="spark ' . esc($class) . '" viewBox="0 0 100 32" preserveAspectRatio="none" aria-hidden="true" dir="ltr"><polygon class="spark-fill" points="0,32 ' . $line . ' 100,32"/><polyline class="spark-line" points="' . $line . '"/></svg>';
}

/* ---------- Charts: server-rendered SVG with an accessible table, no external libraries ---------- */

function chart_ticks(int $max): array
{
    if ($max <= 0) {
        return [0, 1];
    }
    // "Nice" steps (1, 2, 2.5, 5 × 10ⁿ) giving about four gridlines.
    $raw = $max / 4;
    $mag = 10 ** floor(log10($raw));
    $step = $mag * 10;
    foreach ([1, 2, 2.5, 5, 10] as $f) {
        if ($f * $mag >= $raw) {
            $step = $f * $mag;
            break;
        }
    }
    $step = max(1, (int) ceil($step));
    return range(0, $step * (int) ceil($max / $step), $step);
}

function chart_compact(int $n): string
{
    if ($n >= 1000000) {
        return fa_number(rtrim(rtrim(number_format($n / 1000000, 1, '.', ''), '0'), '.')) . ' م';
    }
    if ($n >= 1000) {
        return fa_number(rtrim(rtrim(number_format($n / 1000, 1, '.', ''), '0'), '.')) . ' هزار';
    }
    return fa_number($n);
}

/**
 * Grouped bars (type "bar") or lines (type "line") over the bucket labels. Each series:
 * ['key' => ..., 'label' => ..., 'class' => 's1'|'s2']. Values are read from $buckets[$i][$key].
 * Every mark carries data-tip for the hover/focus tooltip in app.js.
 */
function svg_chart(string $type, array $buckets, array $series, string $unit, string $title, array $opts = []): string
{
    static $uid = 0;
    $uid++;
    $w = (int) ($opts['width'] ?? 600);
    $h = (int) ($opts['height'] ?? 240);
    $left = 78;
    $right = 12;
    $top = 14;
    $bottom = 34;
    $n = max(1, count($buckets));
    $max = 0;
    foreach ($buckets as $b) {
        foreach ($series as $s) {
            $max = max($max, (int) $b[$s['key']]);
        }
    }
    $ticks = chart_ticks($max);
    $top_value = max(1, end($ticks));
    $plotW = $w - $left - $right;
    $plotH = $h - $top - $bottom;
    $x = fn(float $i) => $left + $plotW * $i / $n;
    $y = fn(int $v) => $top + $plotH - $plotH * $v / $top_value;
    $fmt = fn(int $v) => $unit === 'toman' ? toman($v) : fa_number(number_format($v, 0, '.', '٬'));
    $out = '<svg class="chart" data-kind="' . esc($type) . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc($title) . '" dir="ltr">';
    foreach ($ticks as $t) {
        $ty = round($y($t), 1);
        $out .= '<line class="grid" x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . $ty . '" y2="' . $ty . '"/>';
        $out .= '<text class="axis" x="' . ($left - 8) . '" y="' . ($ty + 4) . '" text-anchor="end">' . esc($unit === 'toman' ? chart_compact($t) : fa_number($t)) . '</text>';
    }
    $labelEvery = max(1, (int) ceil($n / 8));
    foreach ($buckets as $i => $b) {
        if ($i % $labelEvery === 0) {
            $out .= '<text class="axis" x="' . round($x($i + 0.5), 1) . '" y="' . ($h - 10) . '" text-anchor="middle">' . esc($b['short']) . '</text>';
        }
    }
    if ($type === 'bar') {
        $group = $plotW / $n;
        $barW = max(2, min(22, ($group - 4) / count($series) - 2));
        foreach ($buckets as $i => $b) {
            $start = $x($i) + ($group - (count($series) * ($barW + 2) - 2)) / 2;
            foreach ($series as $k => $s) {
                $v = (int) $b[$s['key']];
                $bx = round($start + $k * ($barW + 2), 1);
                $by = round($y($v), 1);
                $bh = max(0, round($top + $plotH - $by, 1));
                $tip = $b['label'] . ' — ' . $s['label'] . ': ' . $fmt($v);
                $r = min(4, $barW / 2, $bh);
                // Rounded data end, square at the baseline.
                $path = $bh > 0
                    ? 'M' . $bx . ' ' . ($top + $plotH) . 'V' . ($by + $r) . 'Q' . $bx . ' ' . $by . ' ' . ($bx + $r) . ' ' . $by . 'H' . ($bx + $barW - $r) . 'Q' . ($bx + $barW) . ' ' . $by . ' ' . ($bx + $barW) . ' ' . ($by + $r) . 'V' . ($top + $plotH) . 'Z'
                    : '';
                if ($path) {
                    $out .= '<path class="mark ' . $s['class'] . '" d="' . $path . '"/>';
                }
                $out .= '<rect class="hit" x="' . $bx . '" y="' . $top . '" width="' . round($barW + 2, 1) . '" height="' . $plotH . '" tabindex="0" data-tip="' . esc($tip) . '"><title>' . esc($tip) . '</title></rect>';
            }
        }
    } elseif ($type === 'area') {
        // Smooth curve (Catmull-Rom → cubic Bézier, control points kept inside the plot) with a soft fill.
        $base = $top + $plotH;
        $out .= '<defs>';
        foreach ($series as $k => $s) {
            $out .= '<linearGradient id="ag' . $uid . '-' . $k . '" x1="0" x2="0" y1="0" y2="1"><stop offset="0" class="stop-top ' . $s['class'] . '"/><stop offset="1" class="stop-bottom ' . $s['class'] . '"/></linearGradient>';
        }
        $out .= '</defs>';
        foreach (array_reverse($series, true) as $k => $s) {
            $pts = [];
            foreach ($buckets as $i => $b) {
                $pts[] = [round($x($i + 0.5), 1), round($y((int) $b[$s['key']]), 1)];
            }
            $d = 'M' . $pts[0][0] . ' ' . $pts[0][1];
            $last = count($pts) - 1;
            for ($i = 0; $i < $last; $i++) {
                $p0 = $pts[max(0, $i - 1)];
                $p1 = $pts[$i];
                $p2 = $pts[$i + 1];
                $p3 = $pts[min($last, $i + 2)];
                $c1y = max($top, min($base, $p1[1] + ($p2[1] - $p0[1]) / 6));
                $c2y = max($top, min($base, $p2[1] - ($p3[1] - $p1[1]) / 6));
                $d .= 'C' . round($p1[0] + ($p2[0] - $p0[0]) / 6, 1) . ' ' . round($c1y, 1) . ' ' . round($p2[0] - ($p3[0] - $p1[0]) / 6, 1) . ' ' . round($c2y, 1) . ' ' . $p2[0] . ' ' . $p2[1];
            }
            $out .= '<path class="area ' . $s['class'] . '" fill="url(#ag' . $uid . '-' . $k . ')" d="' . $d . 'L' . $pts[$last][0] . ' ' . $base . 'L' . $pts[0][0] . ' ' . $base . 'Z"/>';
            $out .= '<path class="line ' . $s['class'] . '" pathLength="1" d="' . $d . '"/>';
            if ($n <= 31) {
                foreach ($pts as [$px, $py]) {
                    $out .= '<circle class="dot ' . $s['class'] . '" cx="' . $px . '" cy="' . $py . '" r="3.5"/>';
                }
            }
        }
        foreach ($buckets as $i => $b) {
            $tip = $b['label'] . ' — ' . implode('، ', array_map(fn($s) => $s['label'] . ': ' . $fmt((int) $b[$s['key']]), $series));
            $out .= '<rect class="hit" x="' . round($x($i), 1) . '" y="' . $top . '" width="' . round($plotW / $n, 1) . '" height="' . $plotH . '" tabindex="0" data-tip="' . esc($tip) . '"><title>' . esc($tip) . '</title></rect>';
        }
    } else {
        foreach ($series as $s) {
            $points = [];
            foreach ($buckets as $i => $b) {
                $points[] = round($x($i + 0.5), 1) . ',' . round($y((int) $b[$s['key']]), 1);
            }
            $out .= '<polyline class="line ' . $s['class'] . '" points="' . implode(' ', $points) . '"/>';
            if ($n <= 31) {
                foreach ($points as $p) {
                    [$px, $py] = explode(',', $p);
                    $out .= '<circle class="dot ' . $s['class'] . '" cx="' . $px . '" cy="' . $py . '" r="3.5"/>';
                }
            }
        }
        foreach ($buckets as $i => $b) {
            $tip = $b['label'] . ' — ' . implode('، ', array_map(fn($s) => $s['label'] . ': ' . $fmt((int) $b[$s['key']]), $series));
            $out .= '<rect class="hit" x="' . round($x($i), 1) . '" y="' . $top . '" width="' . round($plotW / $n, 1) . '" height="' . $plotH . '" tabindex="0" data-tip="' . esc($tip) . '"><title>' . esc($tip) . '</title></rect>';
        }
    }
    $out .= '<line class="baseline" x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . ($top + $plotH) . '" y2="' . ($top + $plotH) . '"/>';
    return $out . '</svg>';
}

/* ---------- Excel exports ---------- */

function dashboard_export(string $kind): never
{
    require_once __DIR__ . '/export.php';
    admin_require('export');
    $range = dashboard_range();
    audit('export.dashboard', null, $kind . ' ' . $range['from'] . '..' . $range['to']);
    if ($kind === 'geo') {
        $geo = dashboard_geo($range['from'], $range['to']);
        export_table(
            'provinces-' . $range['from'] . '-' . $range['to'],
            ['استان', 'پزشکان تأییدشده', 'مطب‌های فعال', 'مراکز تأییدشده', 'نوبت‌ها', 'کارمزد رزرو (تومان)', 'درآمد اشتراک پزشکان (تومان)'],
            array_map(fn($r) => [$r['name'], (int) $r['doctors'], (int) $r['clinics'], (int) $r['centers'], (int) $r['bookings'], (int) $r['fee'], (int) $r['subscription']], $geo['provinces']),
        );
    }
    $series = dashboard_series($range['from'], $range['to']);
    export_table(
        'dashboard-' . $range['from'] . '-' . $range['to'],
        ['دوره', 'پزشک جدید', 'بیمار جدید', 'نوبت ثبت‌شده', 'نوبت لغوشده', 'ویزیت انجام‌شده', 'درآمد اشتراک (تومان)', 'درآمد کارمزد رزرو (تومان)', 'جمع درآمد (تومان)'],
        array_map(
            fn($b) => [$b['label'], $b['doctors'], $b['patients'], $b['bookings'], $b['cancelled'], $b['completed'], $b['subscription'], $b['fee'], $b['subscription'] + $b['fee']],
            $series['buckets'],
        ),
    );
}
