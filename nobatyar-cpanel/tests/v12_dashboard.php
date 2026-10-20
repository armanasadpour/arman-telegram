<?php
declare(strict_types=1);

/**
 * 0.12.0 — business dashboard figures, Jalali periods, chart output, admin permissions map, link safety
 * and announcement batching. Runs against the disposable nobatyar_test_http database after the HTTP
 * suites; every fixture row is written inside a transaction that is rolled back.
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

// Jalali calendar helpers.
check(jalali_parts('2026-10-17') === [1405, 7, 25], 'Gregorian to Jalali parts');
check(jalali_month_start('2026-10-17') === '2026-09-23', 'Jalali month start (1 Mehr 1405)');
check(jalali_month_end('2026-10-17') === '2026-10-22', 'Jalali month end (30 Mehr 1405)');
check(jalali_month_end('2027-03-01') === '2027-03-20', 'Esfand end before Nowruz');
check(jalali_year_start('2026-10-17') === '2026-03-21', 'Jalali year start (Nowruz 1405)');
check(jalali_month_label('2026-10-17') === 'مهر ۱۴۰۵', 'Jalali month label');

// Permission map: every handler of admin_dispatch maps to an explicit permission.
$source = file_get_contents(__DIR__ . '/../lib/admin_actions.php');
preg_match('/\$handlers = \[(.*?)\n    \];/s', $source, $m);
preg_match_all("/^        '([a-z_]+)' =>/m", $m[1], $keys);
$actions = array_merge($keys[1], ['user_status', 'link_doctor', 'unlink_doctor', 'grant_scope', 'revoke_scope', 'gateway_test']);
$unmapped = array_filter($actions, fn($a) => admin_action_permission($a) === 'admins' && !str_starts_with($a, 'admin_') && !str_starts_with($a, 'backup_') && !str_starts_with($a, 'update_'));
check(admin_action_permission('update_apply') === 'admins' && admin_action_permission('update_upload') === 'admins', 'system update actions are owner-only');
check(count($actions) > 50 && !$unmapped, 'every admin action has an explicit permission ' . implode(',', $unmapped));
check(admin_action_permission('unknown_action') === 'admins', 'unknown actions need the owner permission');
foreach (admin_roles() as $key => $role) {
    check(!array_diff($role['perms'], array_keys(admin_permissions())), "role $key uses known permissions");
}
check(!in_array('gateways', admin_roles()['manager']['perms'], true) && !in_array('admins', admin_roles()['manager']['perms'], true), 'manager cannot change gateways or administrators');
check(admin_roles()['viewer']['perms'] === ['dashboard'], 'viewer is dashboard-only');

// Masking by role: a support admin sees masked numbers, an owner sees them in full.
$support = (int) query("SELECT id FROM admins WHERE role='support' LIMIT 1")->fetchColumn();
if (!$support) {
    query("INSERT INTO admins(email,password_hash,name,role) VALUES('mask-test@example.test','x','آزمون','support')");
    $support = (int) db()->lastInsertId();
}
$_SESSION['admin_id'] = $support;
$_SESSION['admin_login_at'] = time();
$_SESSION['admin_version'] = (int) query('SELECT session_version FROM admins WHERE id=?', [$support])->fetchColumn();
check(admin() && !admin_sensitive() && admin_mobile('09121234567') === '۰۹۱۲•••۴۵۶۷', 'support role sees masked mobile');
check(admin_identity('1234567890') === '•••••••۸۹۰', 'support role sees masked identity number');
$owner = (int) query("SELECT id FROM admins WHERE role='owner' AND active=1 ORDER BY id LIMIT 1")->fetchColumn();
$_SESSION['admin_id'] = $owner;
$_SESSION['admin_version'] = (int) query('SELECT session_version FROM admins WHERE id=?', [$owner])->fetchColumn();
check(admin() && admin_sensitive() && admin_mobile('09121234567') === '۰۹۱۲۱۲۳۴۵۶۷', 'owner sees full mobile');
$_SESSION['admin_version'] = 999;
check(!admin(), 'stale admin session version is rejected');
$_SESSION['admin_version'] = (int) query('SELECT session_version FROM admins WHERE id=?', [$owner])->fetchColumn();

// Admin-typed links.
foreach (['javascript:alert(1)', '//evil.example', 'http://plain.example', 'centers.php"><script>', 'data:text/html,x'] as $bad) {
    $rejected = false;
    try {
        safe_link($bad);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    check($rejected, 'unsafe link rejected: ' . $bad);
}
check(safe_link('centers.php') === 'centers.php' && safe_link('doctor.php?id=4') === 'doctor.php?id=4' && safe_link('https://example.com/a?b=1') === 'https://example.com/a?b=1', 'site pages and https links accepted');

// KPI deltas: insert known rows, compare against the figures before, then roll everything back.
$today = date('Y-m-d');
$before = dashboard_counts($today, $today);
$revBefore = dashboard_revenue($today, $today);
$seriesBefore = dashboard_series(date('Y-m-d', strtotime('-6 days')), $today)['buckets'];
$expBefore = dashboard_expiring();
$pdo = db();
$pdo->beginTransaction();
try {
    $plan = (int) query("SELECT id FROM subscription_plans WHERE audience='doctor' ORDER BY id LIMIT 1")->fetchColumn();
    $cplan = (int) query("SELECT id FROM subscription_plans WHERE audience='center' ORDER BY id LIMIT 1")->fetchColumn();
    $city = (int) query('SELECT city_id FROM clinics ORDER BY id LIMIT 1')->fetchColumn();
    query("INSERT INTO doctors(name,specialty,city_id,clinic_address,review_status,created_at) VALUES('پزشک داشبورد','عمومی',?,'',  'pending',NOW())", [$city]);
    query("INSERT INTO doctors(name,specialty,city_id,clinic_address,review_status,active,subscription_until,created_at) VALUES('پزشک فعال داشبورد','عمومی',?,'','approved',1,DATE_ADD(CURDATE(),INTERVAL 5 DAY),NOW())", [$city]);
    $doc = (int) $pdo->lastInsertId();
    query("INSERT INTO clinics(doctor_id,name,city_id,address,phone,active,is_primary) VALUES(?,'مطب داشبورد',?,'نشانی','07600000000',1,1)", [$doc, $city]);
    $clinic = (int) $pdo->lastInsertId();
    query("INSERT INTO users(mobile,name,created_at) VALUES('09990001111','بیمار داشبورد',NOW())");
    $patient = (int) $pdo->lastInsertId();
    query("INSERT INTO user_roles(user_id,role) VALUES(?,'patient')", [$patient]);
    query("INSERT INTO slots(doctor_id,clinic_id,starts_at,capacity) VALUES(?,?,CONCAT(CURDATE(),' 23:30:00'),3)", [$doc, $clinic]);
    $slot = (int) $pdo->lastInsertId();
    query("INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,status) VALUES(?,'الف','09990001111',?,'confirmed')", [$slot, bin2hex(random_bytes(16))]);
    $booking = (int) $pdo->lastInsertId();
    query("INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,status) VALUES(?,'ب','09990001112',?,'cancelled')", [$slot, bin2hex(random_bytes(16))]);
    query("INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,status) VALUES(?,'ج','09990001113',?,'held')", [$slot, bin2hex(random_bytes(16))]);
    $tx = function (string $purpose, string $status, int $amount, int $test = 0) use ($booking, $pdo) {
        query(
            "INSERT INTO payment_transactions(purpose,reference_id,gateway,amount_toman,status,order_code,test_mode,verified_at) VALUES(?,?,'zarinpal',?,?,?,?,IF(? IN ('verified','refund_due','refunded'),NOW(),NULL))",
            [$purpose, $booking, $amount, $status, 'T' . bin2hex(random_bytes(8)), $test, $status],
        );
        return (int) $pdo->lastInsertId();
    };
    $tx('booking', 'verified', 5000);
    $tx('booking', 'refunded', 4000);
    $tx('booking', 'failed', 3000);
    $tx('booking', 'verified', 9000, 1);
    $tx('gateway_test', 'verified', 1000);
    $sandbox = $tx('subscription', 'verified', 50000, 1);
    $order = fn(string $table, int $owner, int $planId, int $amount, ?int $txId) => query(
        "INSERT INTO $table(number," . ($table === 'subscription_orders' ? 'doctor_id,kind' : 'center_id') . ",plan_id,plan_name,duration_days,base_toman,payable_toman,status,payment_method,payment_tx_id,expires_at,paid_at) VALUES(?,?," . ($table === 'subscription_orders' ? "'new'," : '') . "?,'پلن',30,?,?,'paid','gateway',?,NOW(),NOW())",
        ['D' . bin2hex(random_bytes(6)), $owner, $planId, $amount, $amount, $txId],
    );
    $order('subscription_orders', $doc, $plan, 120000, null);
    $order('subscription_orders', $doc, $plan, 50000, $sandbox);
    $center = (int) query('SELECT id FROM centers ORDER BY id LIMIT 1')->fetchColumn();
    if ($center && $cplan) {
        $order('center_orders', $center, $cplan, 80000, null);
    }
    query("INSERT INTO refunds(purpose,reference_id,amount_toman,reason,status) VALUES('booking',?,7000,'آزمون','pending')", [$booking]);

    $after = dashboard_counts($today, $today);
    $d = fn(string $k) => $after[$k] - $before[$k];
    check($d('doctors_total') === 2 && $d('doctors_new') === 2 && $d('doctors_pending') === 1, 'doctor counts include new and pending');
    check($d('doctors_active') === 1 && $d('doctors_approved') === 1, 'active doctor needs approval and valid subscription');
    check($d('patients_total') === 1 && $d('patients_new') === 1, 'patients counted by role');
    check($d('clinics_total') === 1, 'active clinics counted');
    check($d('bookings_today') === 1 && $d('bookings_month') === 1 && $d('cancelled_range') === 1, 'bookings exclude holds; cancellations separate');
    check($d('tx_success') === 2 && $d('tx_failed') === 1, 'transactions exclude sandbox and gateway tests');
    check($d('refunds_pending') === 1 && $d('refunds_pending_toman') === 7000, 'pending refunds counted with amount');
    $rev = dashboard_revenue($today, $today);
    $centerPart = $center && $cplan ? 80000 : 0;
    check($rev['fee'] - $revBefore['fee'] === 5000, 'booking-fee revenue counts only real, non-refunded payments');
    check($rev['subscription'] - $revBefore['subscription'] === 120000 + $centerPart, 'subscription revenue includes centers and excludes sandbox orders');
    check($rev['total'] === $rev['fee'] + $rev['subscription'], 'revenue total');
    $series = dashboard_series(date('Y-m-d', strtotime('-6 days')), $today);
    $last = end($series['buckets']);
    $lastBefore = end($seriesBefore);
    check(!$series['monthly'] && count($series['buckets']) === 7, 'seven daily buckets for a week');
    check($last['fee'] - $lastBefore['fee'] === 5000 && $last['subscription'] - $lastBefore['subscription'] === 120000 + $centerPart, 'series matches revenue for today');
    check($last['doctors'] - $lastBefore['doctors'] === 2 && $last['patients'] - $lastBefore['patients'] === 1, 'series growth for today');
    $exp = dashboard_expiring();
    check($exp['doctors_total'] - $expBefore['doctors_total'] === 1, 'expiring subscription listed');
    $geo = dashboard_geo($today, $today);
    $province = (int) query('SELECT province_id FROM cities WHERE id=?', [$city])->fetchColumn();
    $row = current(array_filter($geo['provinces'], fn($r) => (int) $r['id'] === $province));
    check($row && (int) $row['bookings'] >= 1 && (int) $row['fee'] >= 5000, 'province figures attribute bookings and fees');
    $long = dashboard_series(date('Y-m-d', strtotime('-200 days')), $today);
    check($long['monthly'] && count($long['buckets']) >= 7 && count($long['buckets']) <= 8, 'long range folds into Jalali months');
    check(array_sum(array_column($long['buckets'], 'fee')) === dashboard_revenue(date('Y-m-d', strtotime('-200 days')), $today)['fee'], 'monthly buckets add up to the period revenue');
    $svg = svg_chart('bar', $series['buckets'], [['key' => 'subscription', 'label' => 'اشتراک', 'class' => 's1'], ['key' => 'fee', 'label' => 'کارمزد', 'class' => 's2']], 'toman', 'آزمون');
    check(substr_count($svg, 'data-tip=') === 14 && !str_contains($svg, 'NAN') && !str_contains($svg, 'INF'), 'bar chart has a tooltip target per mark');
    $line = svg_chart('line', $series['buckets'], [['key' => 'doctors', 'label' => 'پزشک', 'class' => 's1']], 'count', 'آزمون');
    check(substr_count($line, '<polyline') === 1 && substr_count($line, 'data-tip=') === 7, 'line chart with one hover target per period');
    $empty = svg_chart('bar', [['label' => 'x', 'short' => 'x', 'a' => 0]], [['key' => 'a', 'label' => 'a', 'class' => 's1']], 'count', 'خالی');
    check(str_contains($empty, '<svg') && !str_contains($empty, 'NAN'), 'empty chart renders');
    check(chart_ticks(0) === [0, 1] && chart_ticks(95) === [0, 25, 50, 75, 100] && chart_compact(2500000) === '۲.۵ م', 'axis ticks and compact labels');

    // Announcements: batches advance a cursor; nobody is notified twice.
    query("UPDATE settings SET value='50' WHERE `key`='announcement_batch'");
    $users = (int) query('SELECT COUNT(*) FROM users WHERE active=1')->fetchColumn();
    query("INSERT INTO admin_announcements(title,body,audience,status,total) VALUES('اطلاعیه آزمون دسته‌ای','متن','all','queued',?)", [$users]);
    $aid = (int) $pdo->lastInsertId();
    $sent = 0;
    for ($i = 0; $i < 20 && query('SELECT status FROM admin_announcements WHERE id=?', [$aid])->fetchColumn() !== 'sent'; $i++) {
        $sent += announcements_process(3);
    }
    $a = query('SELECT * FROM admin_announcements WHERE id=?', [$aid])->fetch();
    $notes = (int) query("SELECT COUNT(*) FROM notifications WHERE title='اطلاعیه آزمون دسته‌ای'")->fetchColumn();
    $distinct = (int) query("SELECT COUNT(DISTINCT user_id) FROM notifications WHERE title='اطلاعیه آزمون دسته‌ای'")->fetchColumn();
    check($a['status'] === 'sent' && (int) $a['sent_count'] === $users && $notes === $users && $distinct === $users, 'announcement delivered once per user in batches of three');
    check(announcements_process(3) === 0, 'finished announcement is not resent');
} finally {
    $pdo->rollBack();
}
check(dashboard_counts($today, $today) === $before, 'fixtures rolled back');
echo "SUCCESS $checks v0.12 dashboard checks\n";
