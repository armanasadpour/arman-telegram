<?php
require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=doctor-panel.php');
}
$u = require_user();
$mine = my_doctor((int) $u['id']);
// 1.0.0-rc.14: «ورود پزشکان» for an account with no doctor profile leads to registration (or to the
// secretary panel) instead of a dead-end «پزشک پیدا نشد».
if (!$mine && !isset($_GET['doctor'])) {
    redirect(has_role('secretary') ? 'secretary.php' : 'register.php');
}
$id = (int) ($_GET['doctor'] ?? ($mine['id'] ?? 0));
// rc.20: re-check plan locks when the doctor opens the panel (Pro renewed or expired since the daily check).
if ($mine && (int) $mine['id'] === $id) {
    entitlement_sync($id);
}
$tabs = [
    'dashboard' => 'نمای کلی',
    'profile' => 'پروفایل پزشک',
    'clinics' => 'مطب‌ها',
    'services' => 'خدمات',
    'schedule' => 'برنامه کاری',
    'calendar' => 'تقویم نوبت‌ها',
    'bookings' => 'مدیریت نوبت‌ها',
    'checkin' => 'چک نوبت',
    'history' => 'سوابق مراجعه',
    'staff' => 'منشی و دسترسی‌ها',
    'centers' => 'مراکز درمانی',
    'report' => 'گزارش عملکرد',
    'reviews' => 'نظرات بیماران',
    'promote' => 'لینک، QR و اطلاعیه',
    'subscription' => 'اشتراک پرو',
    'notifications' => 'اعلان‌ها',
    'events' => 'گزارش تغییرات',
];
// 1.0.0-rc.17: with subscription sales off the menu item and its page do not exist.
if (!subscriptions_on()) {
    unset($tabs['subscription']);
}
// 1.0.30: replies to reviews exist only while reviews are on.
if (!search_config()['reviews']) {
    unset($tabs['reviews']);
}
$tab = (string) ($_GET['tab'] ?? 'dashboard');
try {
    $d = doctor_actor($id, 'view', 0, true);
    if (!isset($tabs[$tab])) {
        throw new AuthError('صفحه پیدا نشد.', 404);
    }
    if (in_array($tab, ['profile', 'clinics', 'services', 'staff', 'events', 'subscription', 'centers', 'report', 'promote', 'reviews'], true)) {
        doctor_actor($id, 'owner', 0, true);
    }
    $clinics = doctor_clinics_for($id);
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    layout_start('پنل پزشک');
    echo '<p class="error">' . esc(error_message($e)) . '</p><a href="panel.php">بازگشت به حساب من</a>';
    layout_end();
    exit();
}
// 1.0.0-rc.11: one day's appointments as an Excel file (clinic 0 = every clinic this viewer may see).
if (($_GET['export'] ?? '') === 'day') {
    require_once __DIR__ . '/lib/day_export.php';
    try {
        $day = isset($_GET['date']) && $_GET['date'] !== '' ? parse_jdate(field($_GET, 'date', 10)) : date('Y-m-d');
        day_export_send($id, $day, (int) ($_GET['clinic'] ?? 0));
    } catch (Throwable $e) {
        http_response_code($e instanceof AuthError ? $e->status : 422);
        layout_start('پنل پزشک');
        echo '<p class="error">' . esc(error_message($e)) . '</p><a href="doctor-panel.php?doctor=' . $id . '">بازگشت به پنل</a>';
        layout_end();
        exit();
    }
}
$clinic = (int) ($_GET['clinic'] ?? ($clinics[0]['id'] ?? 0));
$selected = null;
foreach ($clinics as $c) {
    if ((int) $c['id'] === $clinic) {
        $selected = $c;
    }
}
if (!$selected && $clinics) {
    $selected = $clinics[0];
    $clinic = (int) $selected['id'];
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = field($_POST, 'action', 30);
        if ($action === 'photo') {
            save_doctor_photo($id, $_FILES['photo'] ?? []);
            $result = ['message' => 'تصویر ذخیره شد.'];
        } elseif (str_starts_with($action, 'sub_')) {
            doctor_actor($id, 'owner', 0, true);
            $result = doctor_subscription_action($id, $action, $_POST);
            if (isset($result['redirect'])) {
                gateway_send($result['redirect']);
            }
        } elseif (str_starts_with($action, 'center_')) {
            $result = ['message' => doctor_center_action($id, $action, $_POST)];
        } elseif ($action === 'review_reply') {
            $result = ['message' => review_reply_save($id, $_POST)];
        } elseif ($action === 'notice_save') {
            $result = ['message' => doctor_notice_save($id, $_POST)];
        } elseif ($action === 'pro_prefs') {
            // rc.20: Pro preferences (end-of-day summary SMS on/off).
            doctor_actor($id, 'owner', 0, true);
            query('UPDATE doctors SET daily_summary_off=? WHERE id=?', [empty($_POST['daily_summary']) ? 1 : 0, $id]);
            $result = ['message' => 'تنظیمات ذخیره شد.'];
        } elseif ($action === 'notification_read') {
            read_notification(positive_id($_POST['id'] ?? 0));
            $result = ['message' => 'اعلان خوانده شد.'];
        } else {
            $result = doctor_action($id, $action, $_POST);
        }
        $_SESSION['flash'] = $result['message'];
        redirect('doctor-panel.php?doctor=' . $id . '&tab=' . $tab . '&clinic=' . $clinic);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
require __DIR__ . '/lib/doctor_views.php';
$panelTabs = $tabs;
if (!$d['is_owner']) {
    foreach (['profile','clinics','services','staff','events','subscription','centers','report','promote'] as $key) unset($panelTabs[$key]);
    if (!array_filter($clinics, fn($c)=>!empty($c['can_manage_schedule']))) unset($panelTabs['schedule']);
    if (!array_filter($clinics, fn($c)=>!empty($c['can_view_history']))) unset($panelTabs['history']);
    if (!array_filter($clinics, fn($c)=>!empty($c['can_manage_bookings']))) unset($panelTabs['bookings']);
}
set_panel_navigation('doctor-panel.php', $panelTabs, $tab, $id, $clinic, $d['is_owner'] ? 'پنل پزشک' : 'پنل منشی');
layout_start('پنل ' . $d['name'], $tab === 'promote' ? ['body_class' => 'promote-page'] : []);
echo '<div class="workspace"><aside class="doctor-sidebar"><a class="brand-mark" href="doctor-panel.php?doctor=' .
    $id .
    '">نوبت‌یار <small>فضای کار پزشک</small></a><div class="identity">';
doctor_avatar($d);
echo '<strong>' .
    esc(doctor_display_name($d['name'])) .
    '</strong><small>' .
    ($d['is_owner'] ? 'حساب پزشک' : 'دسترسی منشی') .
    '</small></div><nav aria-label="پنل پزشک">';
foreach ($tabs as $key => $label) {
    if (!$d['is_owner'] && in_array($key, ['profile', 'clinics', 'services', 'staff', 'events', 'subscription', 'report', 'promote'], true)) {
        continue;
    }
    $proOnly = ['report' => 'monthly_report', 'promote' => 'booking_qr'][$key] ?? null;
    if (
        !$d['is_owner'] &&
        $key === 'schedule' &&
        !array_filter($clinics, fn($c) => !empty($c['can_manage_schedule']))
    ) {
        continue;
    }
    if (
        !$d['is_owner'] &&
        $key === 'history' &&
        !array_filter($clinics, fn($c) => !empty($c['can_view_history']))
    ) {
        continue;
    }
    echo '<a ' .
        ($tab === $key ? 'class="current" aria-current="page"' : '') .
        ' href="doctor-panel.php?doctor=' .
        $id .
        '&tab=' .
        $key .
        '&clinic=' .
        $clinic .
        '">' .
        esc($label) .
        ($proOnly && !doctor_has($id, $proOnly, $d) ? ' <b class="pro-chip">پرو</b>' : '') .
        '</a>';
}
echo '</nav><a class="back-account" href="panel.php">بازگشت به حساب من</a></aside><section class="doctor-content"><div class="workspace-heading"><div><span class="eyebrow">فضای کار • ' .
    esc(jdate(date('Y-m-d'))) .
    '</span><h1>' .
    esc($tabs[$tab]) .
    '</h1></div><a class="button secondary" href="doctor.php?id=' .
    $id .
    '">صفحه عمومی پزشک ↗</a></div>';
if ($error) {
    echo '<p class="error" role="alert">' . esc($error) . '</p>';
}
if ($d['review_status'] !== 'approved' || !$d['active']) {
    echo '<p class="notice">انتشار عمومی پزشک فعال نیست. نوبت‌های قبلی در این پنل قابل مدیریت‌اند.</p>';
}
try {
    doctor_view($tab, $d, $clinics, $selected);
} catch (Throwable $e) {
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
}
echo '</section></div>';
layout_end();
