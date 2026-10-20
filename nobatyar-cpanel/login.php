<?php
/**
 * Full-page sign-in and sign-up (mobile → one-time code → name for new numbers). The same steps open as a
 * bottom sheet on phones and a dialog on desktops (views/auth/form.php + assets/shell.js); this page is
 * their no-JavaScript fallback and the target of every "please sign in" redirect.
 */
require __DIR__ . '/core.php';
require_phase2();
$error = '';
$next = login_next($_POST['next'] ?? ($_GET['next'] ?? ($_SESSION['login_next'] ?? 'panel.php')));
$_SESSION['login_next'] = $next;
$target = static function (string $next): string {
    if ($next === 'reception-payment.php') {
        return $_SESSION['reception_next'] ?? 'patient.php';
    }
    if ($next === 'service-book.php') {
        return $_SESSION['booking_next'] ?? 'panel.php';
    }
    return $next;
};
$finish = static function () use ($next, $target): never {
    unset($_SESSION['otp_login'], $_SESSION['otp_mobile'], $_SESSION['login_next']);
    redirect($target($next));
};
$current = user_now();
if ($current && trim((string) $current['name']) !== '') {
    redirect($target($next));
}
$step = $current || pending_signup() ? 'profile' : (!empty($_SESSION['otp_login']) ? 'code' : 'phone');
if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'request' || $action === 'resend') {
            $mobile = normalize_mobile($action === 'resend' ? (string) ($_SESSION['otp_mobile'] ?? '') : field($_POST, 'mobile', 20));
            $otp = otp_request($mobile);
            $_SESSION['otp_login'] = $otp + ['sent_at' => time()];
            $_SESSION['otp_mobile'] = $mobile;
            redirect('login.php');
        } elseif ($action === 'verify') {
            $step = 'code';
            $r = otp_verify($_SESSION['otp_login']['challenge_id'] ?? '', field($_POST, 'code', 20));
            unset($_SESSION['otp_login']);
            if ($r['status'] === 'logged_in') {
                $finish();
            }
            redirect('login.php');
        } elseif ($action === 'complete') {
            $step = 'profile';
            auth_complete_signup($_POST);
            $finish();
        } elseif ($action === 'change') {
            unset($_SESSION['otp_login'], $_SESSION['signup']);
            redirect('login.php');
        }
    } catch (Throwable $e) {
        $error = error_message($e);
        if ($action === 'request' || ($action === 'resend' && empty($_SESSION['otp_login']))) {
            $step = 'phone';
        }
    }
}
$sentAt = (int) ($_SESSION['otp_login']['sent_at'] ?? 0);
$resendIn = $step === 'code' ? max(0, (int) ($_SESSION['otp_login']['resend_after'] ?? 60) - (time() - $sentAt)) : 0;
$mobile = (string) ($current['mobile'] ?? (pending_signup()['mobile'] ?? ($_SESSION['otp_mobile'] ?? '')));
layout_start('ورود یا ثبت‌نام', ['body_class' => 'login-page', 'description' => 'ورود و ثبت‌نام در نوبت‌یار با شماره موبایل و کد پیامکی']);
echo '<section class="auth-page card" aria-labelledby="lp-title">';
render('auth/form', [
    'step' => $step,
    'mobile' => $mobile,
    'error' => $error,
    'resendIn' => $resendIn,
    'available' => sms_enabled() || sms_dev_mode(),
    'next' => $next,
    'idp' => 'lp-',
]);
echo '</section>';
layout_end();
