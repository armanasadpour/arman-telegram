<?php
declare(strict_types=1);

/** Registration of a clinic or medical center by a signed-in user; the center starts pending review. */

require __DIR__ . '/core.php';
require_schema();
if (!user_now()) {
    redirect('login.php?next=center-register.php');
}
$error = '';
if (is_post()) {
    verify_csrf();
    try {
        $id = center_register($_POST, $_FILES);
        flash('درخواست ثبت مرکز ارسال شد. پس از تأیید مدارک، برای فعال‌سازی امکانات کلینیک اشتراک پرو تهیه کنید.');
        redirect('center.php?center=' . $id);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
layout_start('ثبت کلینیک یا مرکز درمانی', ['body_class'=>'auth-page doctor-register-page']);
if ($error) echo '<p class="error" role="alert">'.esc($error).'</p>';
if (setting('center_registration_enabled','1')!=='1') echo '<p class="notice">ثبت مرکز جدید در حال حاضر غیرفعال است.</p>';
else render('center/register',['v'=>$error?$_POST:[], 'error'=>$error]);
layout_end();
