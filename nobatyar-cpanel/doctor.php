<?php
/**
 * Doctor page in two modes:
 *  - profile (doctor.php?id=): introduction, clinics, booking card, reviews, questions, related doctors;
 *  - time picker (with date, service, move or times=1): day strip and free times of one clinic, the month
 *    calendar, the waitlist and times published before 0.4.
 * Choosing a time goes to service-book.php (patient step), then checkout.php (payment) and receipt.php.
 */
require __DIR__ . '/core.php';
require_phase2();
$id = (int) ($_GET['id'] ?? 0);
$d = query(
    'SELECT d.*,sp.name specialty_name,sp.id specialty_id FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id WHERE d.id=? AND ' . visible_doctor_sql(),
    [$id],
)->fetch();
$clinics = $d
    ? query(
        'SELECT cl.*,c.name city,p.name province FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.doctor_id=? AND ' .
            public_clinic_sql() .
            ' ORDER BY cl.is_primary DESC,cl.id',
        [$id],
    )->fetchAll()
    : [];
if (!$d || !$clinics) {
    http_response_code(404);
    layout_start('پزشک یافت نشد');
    echo '<div class="empty-state wide"><p>پروفایل یا مطب فعالی برای این پزشک پیدا نشد.</p><a class="button secondary" href="index.php?view=doctors">جستجوی پزشک</a></div>';
    layout_end();
    exit();
}
// rc.20: profile views for the doctor's report (not counted for the doctor's own account).
$viewer = user_now();
if (!$viewer || !query('SELECT 1 FROM doctor_accounts WHERE doctor_id=? AND user_id=?', [$id, $viewer['id']])->fetch()) {
    profile_view_count($id);
}
$move = (int) ($_GET['move'] ?? 0);
if ($move) {
    try {
        $moving = own_booking($move);
        if ((int) $moving['doctor_id'] !== $id) {
            throw new AuthError('پزشک نامعتبر است.', 403);
        }
    } catch (Throwable $e) {
        http_response_code(403);
        exit('نوبت در دسترس نیست.');
    }
}
$selected = (int) ($_GET['clinic'] ?? $clinics[0]['id']);
$cl = null;
foreach ($clinics as $c) {
    if ((int) $c['id'] === $selected) {
        $cl = $c;
    }
}
if (!$cl) {
    http_response_code(404);
    layout_start('مطب یافت نشد');
    echo '<div class="empty-state wide"><p>این مطب در دسترس نیست.</p><a class="button secondary" href="doctor.php?id=' . $id . '">پروفایل پزشک</a></div>';
    layout_end();
    exit();
}
$services = query('SELECT * FROM doctor_services WHERE doctor_id=? AND active=1 AND plan_locked=0 ORDER BY id', [$id])->fetchAll();
$service = (int) ($_GET['service'] ?? ($services[0]['id'] ?? 0));
if ($services && !in_array($service, array_map('intval', array_column($services, 'id')), true)) {
    $service = (int) $services[0]['id'];
}
$timesMode = isset($_GET['date']) || isset($_GET['service']) || $move || isset($_GET['times']);
[$fee] = booking_fee_for($selected);
clinic_free_ensure(array_column($clinics, 'id'));
$free = query('SELECT clinic_id,first_at FROM clinic_free_cache WHERE clinic_id IN (' . placeholders(array_column($clinics, 'id')) . ')', array_column($clinics, 'id'))->fetchAll(PDO::FETCH_KEY_PAIR);
$auth = user_now() ? '' : ' data-auth-required';

$waitError = '';
if (is_post() && ($_POST['action'] ?? '') === 'waitlist') {
    verify_csrf();
    if (!user_now()) {
        redirect('login.php?next=' . rawurlencode('doctor.php?id=' . $id . '&clinic=' . $selected));
    }
    try {
        waitlist_join($_POST + ['clinic_id' => $selected, 'service_id' => $service]);
        flash('در صف انتظار ثبت شدید. با آزادشدن زمان در این بازه به شما اطلاع می‌دهیم.');
        redirect('patient.php?tab=waitlist');
    } catch (Throwable $e) {
        $waitError = error_message($e);
        $timesMode = true;
    }
}
require __DIR__ . '/lib/doctor_views.php';

if ($timesMode) {
    $error = '';
    $strip = $service ? booking_day_strip($selected, $service, booking_days(), $move) : [];
    $day = '';
    try {
        if (isset($_GET['date']) && is_scalar($_GET['date']) && $_GET['date'] !== '') {
            $day = parse_jdate(field($_GET, 'date', 10));
        }
    } catch (Throwable $e) {
        $error = error_message($e);
    }
    if ($day === '' || $day < date('Y-m-d')) {
        // Opens on the first day with free times, or today.
        $firstFree = array_values(array_filter($strip, fn($s) => $s['state'] === 'free'));
        $day = $firstFree ? $firstFree[0]['date'] : date('Y-m-d');
    }
    $times = [];
    try {
        $times = $service ? availability($selected, $service, $day, $move) : [];
    } catch (Throwable $e) {
        $error = error_message($e);
    }
    // "Next free time" looks past the visible strip as well (up to 90 days).
    $next = null;
    if (!$times && $service) {
        foreach ($strip as $s) {
            if ($s['state'] === 'free' && $s['date'] > $day) {
                $next = $s['date'];
                break;
            }
        }
        if (!$next) {
            $f = first_available($selected, 90);
            $next = $f && substr($f['starts_at'], 0, 10) > $day ? substr($f['starts_at'], 0, 10) : null;
        }
    }
    $legacy = [];
    if (!$move) {
        foreach (query(
            "SELECT s.*,(SELECT COUNT(*) FROM bookings b WHERE b.slot_id=s.id AND b.status IN ('confirmed','completed','no_show')) booked FROM slots s WHERE s.doctor_id=? AND s.clinic_id=? AND s.source='legacy' AND s.starts_at>NOW() ORDER BY s.starts_at LIMIT 100",
            [$id, $selected],
        )->fetchAll() as $s) {
            if ($s['booked'] < $s['capacity'] && legacy_slot_open($s)) {
                $legacy[] = $s;
            }
        }
    }
    $vars = compact('d', 'clinics', 'cl', 'selected', 'services', 'service', 'move', 'strip', 'day', 'times', 'next', 'legacy', 'error', 'waitError', 'fee', 'auth', 'free');
    if (($_GET['partial'] ?? '') === 'times') {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        render('public/doctor-times', $vars);
        exit();
    }
    layout_start('انتخاب نوبت ' . $d['name'], ['body_class' => 'booking-page times-page', 'robots' => 'noindex,follow', 'canonical' => doctor_url($id, $d['name'])]);
    render('public/doctor-times', $vars);
    layout_end();
    exit();
}

$rsort = in_array($_GET['rsort'] ?? '', ['new', 'high', 'low'], true) ? $_GET['rsort'] : 'new';
$related = [];
$relatedIds = query(
    'SELECT DISTINCT d.id FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE ' .
        visible_doctor_sql() . ' AND ' . public_clinic_sql() . ' AND d.id<>? AND (sp.id=? OR sp.parent_id=?) AND cl.city_id=? ORDER BY d.featured DESC,d.id DESC LIMIT 4',
    [$id, $d['specialty_id'], $d['specialty_id'], $cl['city_id']],
)->fetchAll(PDO::FETCH_COLUMN);
if ($relatedIds) {
    $related = search_cards(array_map('intval', $relatedIds), [(int) $cl['city_id']]);
}
$done = (int) query("SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.status='completed' AND s.doctor_id=?", [$id])->fetchColumn();
$cfg = search_config();
layout_start($d['name'] . ' | ' . $d['specialty_name'], [
    'body_class' => 'doctor-page',
    'description' => $d['name'] . '، ' . $d['specialty_name'] . ' در ' . $cl['city'] . ' — نوبت اینترنتی، نشانی مطب، ساعت کاری و نظر بیماران.',
    'canonical' => doctor_url($id, $d['name']),
    'jsonld' => [
        '@context' => 'https://schema.org',
        '@type' => 'Physician',
        'name' => $d['name'],
        'medicalSpecialty' => $d['specialty_name'],
        'address' => ['@type' => 'PostalAddress', 'addressLocality' => $cl['city'], 'addressRegion' => $cl['province'], 'streetAddress' => $cl['address']],
    ],
]);
render('public/doctor-profile', [
    'd' => $d,
    'clinics' => $clinics,
    'cl' => $cl,
    'selected' => $selected,
    'services' => $services,
    'fee' => $fee,
    'free' => $free,
    'reviews' => doctor_reviews($id, 20, $rsort),
    'dist' => $cfg['reviews'] ? review_distribution($id) : [],
    'rsort' => $rsort,
    'related' => $related,
    'done' => $cfg['show_done'] ? $done : 0,
    'hours' => clinic_hours($selected),
    'cfg' => $cfg,
]);
layout_end();
