<?php
declare(strict_types=1);

/**
 * Administration panel router: admin.php?tab=<section>.
 * POST requests carry an "action" handled by admin_dispatch(); GET requests render views/admin/<section>.php.
 */

require __DIR__ . '/core.php';
require_schema();
require __DIR__ . '/lib/admin_actions.php';
require __DIR__ . '/lib/admin_panel.php';
require __DIR__ . '/lib/jobs.php';
require __DIR__ . '/lib/admin_dashboard.php';
require __DIR__ . '/lib/updater.php';

$sections = admin_sections();
$tab = (string) ($_GET['tab'] ?? 'dashboard');
if (!isset($sections[$tab])) {
    $tab = 'dashboard';
}
$error = '';

if (is_post() && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > update_ini_bytes((string) ini_get('post_max_size'))) {
    // PHP drops the whole form when it is larger than post_max_size; say why instead of «درخواست نامعتبر».
    $error = 'حجم فایل از حداکثر مجاز هاست (' . update_size_label(update_upload_limit()) . ') بیشتر است. post_max_size و upload_max_filesize را در سی‌پنل بیشتر کنید.';
} elseif (is_post()) {
    verify_csrf();
    try {
        $action = (string) ($_POST['action'] ?? '');
        // Sign-in (rc.13): mobile → SMS code → e-mail and password; without a registered mobile: password → mobile → code.
        if ($action === 'login') {
            admin_login((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''));
            redirect('admin.php');
        }
        if ($action === 'admin_otp_send') {
            admin_otp_start((string) ($_POST['mobile'] ?? ''), ($_POST['purpose'] ?? '') === 'register' ? 'register' : 'login');
            redirect('admin.php');
        }
        if ($action === 'admin_otp_verify') {
            admin_otp_confirm((string) ($_POST['code'] ?? ''));
            redirect('admin.php');
        }
        if ($action === 'admin_auth_reset') {
            admin_auth_reset();
            redirect('admin.php');
        }
        if ($action === 'logout') {
            audit('admin.logout');
            logout_admin();
            redirect('admin.php');
        }
        if ($action === 'gateway_test') {
            admin_require('gateways');
            // A real, small payment by the administrator proves the credentials end to end.
            gateway_send(gateway_test_start((string) ($_POST['gateway'] ?? '')));
        }
        $message = admin_dispatch($action, $_POST, $_FILES);
        flash($message ?? 'تغییرات ذخیره شد.');
        // The doctor's own page no longer exists after a permanent delete.
        redirect($action === 'doctor_purge' ? 'admin.php?tab=doctors&status=deleted' : admin_return_url($tab));
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}

$allowed = admin() && ($sections[$tab]['perm'] === null || admin_can($sections[$tab]['perm']));
$export = (string) ($_GET['export'] ?? '');
if ($allowed && $export !== '' && !admin_can('export')) {
    http_response_code(403);
    $error = 'خروجی اکسل برای نقش شما فعال نیست.';
    $export = '';
}

if ($allowed && $tab === 'security' && isset($_GET['download'])) {
    try {
        admin_require('admins');
        backup_download((string) $_GET['download']);
    } catch (Throwable $e) {
        http_response_code(403);
        $error = error_message($e);
    }
}

if ($allowed && $tab === 'dashboard' && in_array($export, ['xlsx', 'geo'], true)) {
    dashboard_export($export === 'geo' ? 'geo' : 'series');
}

if ($allowed && $tab === 'audit' && $export === 'xlsx') {
    require __DIR__ . '/lib/export.php';
    [$filters, $where, $args] = admin_audit_filters();
    audit('export.audit', null, $filters['from'] . '..' . $filters['to']);
    export_table(
        'activity-' . $filters['from'] . '-' . $filters['to'],
        ['شناسه', 'زمان', 'رویداد', 'کد رویداد', 'مدیر', 'کاربر', 'شناسه مورد', 'جزئیات', 'IP'],
        array_map(
            fn($e) => [
                (int) $e['id'],
                jdate($e['created_at'], true),
                audit_label($e['action']),
                $e['action'],
                (string) ($e['admin_email'] ?? ''),
                $e['actor_user_id'] ? (admin_sensitive() ? (string) $e['user_mobile'] : digits(mask_mobile((string) $e['user_mobile']))) : '',
                (int) $e['entity_id'],
                (string) $e['detail'],
                admin_sensitive() ? (string) $e['ip'] : '',
            ],
            admin_audit_rows($where, $args, 20000),
        ),
    );
}

if ($allowed && $tab === 'subscriptions' && $export === 'xlsx') {
    require __DIR__ . '/lib/export.php';
    [$from, $to] = admin_date_range();
    $report = subscription_revenue($from, $to, (int) ($_GET['province'] ?? 0));
    audit('export.subscriptions');
    export_table(
        'subscription-revenue-' . $from . '-' . $to,
        ['شماره فاکتور', 'تاریخ پرداخت', 'پزشک', 'استان', 'شهر', 'پلن', 'نوع', 'روش پرداخت', 'مبلغ پلن', 'تخفیف', 'مالیات', 'مبلغ پرداختی (تومان)'],
        array_map(
            fn($r) => [
                $r['number'],
                jdate($r['paid_at'], true),
                $r['doctor'],
                $r['province'],
                $r['city'],
                $r['plan_name'],
                subscription_order_kinds()[$r['kind']] ?? $r['kind'],
                subscription_kind_label($r['payment_method']),
                (int) $r['base_toman'],
                (int) $r['discount_toman'],
                (int) $r['vat_toman'],
                (int) $r['payable_toman'],
            ],
            $report['rows'],
        ),
    );
}

if ($allowed && $tab === 'payments' && $export === 'xlsx') {
    require __DIR__ . '/lib/export.php';
    [$filters, $where, $args] = admin_payment_filters();
    audit('export.payments');
    export_table(
        'payments-' . $filters['from'] . '-' . $filters['to'],
        ['شناسه', 'تاریخ', 'نوع', 'درگاه', 'وضعیت', 'مبلغ (تومان)', 'شماره سفارش', 'مرجع بانک', 'کارت', 'پزشک', 'موبایل پرداخت‌کننده', 'خطا'],
        array_map(
            fn($t) => [
                (int) $t['id'],
                jdate($t['created_at'], true),
                payment_purpose_label($t['purpose']),
                payment_gateways()[$t['gateway']]['label'] ?? $t['gateway'],
                payment_status_label($t['status']),
                (int) $t['amount_toman'],
                (string) $t['gateway_order_id'],
                (string) $t['ref_num'],
                (string) $t['card_mask'],
                (string) $t['doctor'],
                admin_sensitive() ? (string) $t['mobile'] : ($t['mobile'] ? digits(mask_mobile((string) $t['mobile'])) : ''),
                (string) $t['error'],
            ],
            admin_payment_rows($where, $args, 20000),
        ),
    );
}

if (!admin()) {
    layout_start('ورود مدیرکل', ['body_class' => 'auth-page']);
    render('admin/login', ['error' => $error, 'state' => admin_auth_state(), 'sms' => admin_sms_login_on(), 'first' => isset($_GET['first'])]);
    layout_end();
    exit();
}

// Light or dark administration theme (1.0.0-rc.10): a presentation preference kept in a cookie.
if (isset($_GET['theme']) && in_array($_GET['theme'], ['dark', 'light'], true)) {
    admin_theme_set((string) $_GET['theme']);
    $back = $_GET;
    unset($back['theme']);
    redirect('admin.php' . ($back ? '?' . http_build_query($back) : ''));
}

if (!$allowed) {
    http_response_code(403);
}
$theme = admin_theme();
$viewData = $allowed ? admin_view_data($tab, $error) : [];
$headingAside = '';
if ($allowed && $tab === 'dashboard') {
    $headingAside = render_to_string('admin/dashboard_toolbar', $viewData);
}
layout_start($sections[$tab]['label'] . ' — مدیریت', ['body_class' => 'admin-page admin-' . $theme]);
render('admin/shell_start', [
    'sections' => admin_visible_sections(),
    'tab' => $tab,
    'title' => $sections[$tab]['label'],
    'error' => $error,
    'me' => admin_current(),
    'theme' => $theme,
    'badges' => admin_nav_badges(),
    'headingAside' => $headingAside,
]);
if ($allowed) {
    render('admin/' . $sections[$tab]['view'], $viewData);
} else {
    render('admin/denied', ['permission' => admin_permissions()[$sections[$tab]['perm']] ?? '']);
}
render('admin/shell_end');
layout_end();
