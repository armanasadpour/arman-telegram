<?php
require __DIR__ . '/core.php';
require_phase2();
$error = '';
$result = null;
try {
    throttle('payment_callback', 60, 3600);
    if (($_GET['Status'] ?? '') !== 'OK') {
        throw new AuthError('پرداخت تکمیل نشد. در حساب خود وضعیت رزرو را بررسی کنید.');
    }
    $result = verify_payment(field($_GET, 'Authority', 64));
} catch (Throwable $e) {
    $error = error_message($e);
}
layout_start('نتیجه پرداخت');
echo '<article class="card narrow"><h1>نتیجه پرداخت کارمزد رزرو</h1>';
if ($error) {
    echo '<p class="error">' . esc($error) . '</p>';
} elseif ($result['status'] === 'verified') {
    echo '<p class="notice">پرداخت تأیید و نوبت قطعی شد.</p>';
} else {
    echo '<p class="notice">پرداخت تأیید شد اما این نوبت قابل قطعی‌شدن نیست. درخواست پیگیری بازگشت وجه ثبت شده است.</p>';
}
echo '<a class="button" href="patient.php">مشاهده حساب و رسید</a></article>';
layout_end();
