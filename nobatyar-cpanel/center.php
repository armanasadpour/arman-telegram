<?php
declare(strict_types=1);

/** Clinic / medical center panel: center.php?center=ID&tab=<section>. */

require __DIR__ . '/core.php';
require_schema();
require __DIR__ . '/lib/center_panel.php';
if (!user_now()) {
    redirect('login.php?next=center.php');
}
$mine = my_centers();
if (!$mine) {
    layout_start('پنل مرکز درمانی');
    echo '<article class="card narrow"><h1>پنل مرکز درمانی</h1><p>حساب شما به هیچ مرکز درمانی متصل نیست.</p><p><a class="button" href="center-register.php">ثبت کلینیک یا مرکز درمانی</a> <a class="button secondary" href="panel.php">بازگشت</a></p></article>';
    layout_end();
    exit();
}
$centerId = (int) ($_GET['center'] ?? $mine[0]['id']);
$tabs = center_tabs();
$tab = (string) ($_GET['tab'] ?? 'dashboard');
if (!isset($tabs[$tab])) {
    $tab = 'dashboard';
}
$error = '';
try {
    $a = center_actor($centerId);
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 403);
    layout_start('پنل مرکز درمانی');
    echo '<p class="error">' . esc(error_message($e)) . '</p><a href="center.php">بازگشت</a>';
    layout_end();
    exit();
}
$back = 'center.php?' . http_build_query(['center' => $centerId, 'tab' => $tab] + array_intersect_key($_GET, array_flip(['branch', 'from', 'to', 'doctor', 'view'])));
if (is_post()) {
    verify_csrf();
    try {
        $result = center_dispatch($centerId, field($_POST, 'action', 40), $_POST, $_FILES);
        if (isset($result['redirect'])) {
            gateway_send($result['redirect']);
        }
        flash($result['message']);
        redirect($back);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
if ($tab === 'reports' && ($_GET['export'] ?? '') === 'xlsx') {
    try {
        center_actor($centerId, 'reports');
        require __DIR__ . '/lib/export.php';
        $data = center_view_data('reports', $a);
        $rows = [];
        foreach (['by_doctor' => 'پزشک', 'by_branch' => 'شعبه'] as $key => $kind) {
            foreach ($data['report'][$key] as $r) {
                $rows[] = [$kind, $r['name'], (int) $r['total'], (int) $r['completed'], (int) $r['no_show'], (int) $r['cancelled'], (int) $r['upcoming'], (int) $r['online'], (int) $r['staff'], $r['no_show_rate']];
            }
        }
        center_event($centerId, 'report.export', ['from' => $data['f']['from'], 'to' => $data['f']['to']]);
        export_table('center-report-' . $data['f']['from'] . '-' . $data['f']['to'], ['نوع', 'نام', 'کل نوبت', 'ویزیت‌شده', 'عدم مراجعه', 'لغو', 'آینده', 'آنلاین', 'تلفنی/حضوری', 'نرخ عدم مراجعه ٪'], $rows);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$a = center_actor($centerId);
$allowed = function (string $perm) use ($a): bool {
    return $perm === 'view' || !empty($a['staff']['perm_' . $perm]);
};
layout_start($tabs[$tab]['label'] . ' — ' . $a['center']['name'], ['body_class' => 'center-page']);
render('center/shell_start', ['a' => $a, 'tabs' => $tabs, 'tab' => $tab, 'mine' => $mine, 'allowed' => $allowed, 'error' => $error]);
if (!$allowed($tabs[$tab]['perm'])) {
    echo '<p class="error">این بخش در سطح دسترسی شما نیست.</p>';
} else {
    try {
        render('center/' . $tab, center_view_data($tab, $a) + ['error' => $error, 'post' => $error ? $_POST : []]);
    } catch (Throwable $e) {
        echo '<p class="error">' . esc(error_message($e)) . '</p>';
    }
}
render('center/shell_end');
layout_end();
