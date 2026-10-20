<?php
require __DIR__ . '/core.php';
require_phase2();
$code = (string) ($_GET['code'] ?? '');
if (!preg_match('/^([0-9]{12}|[A-F0-9]{32})$/', $code)) {
    http_response_code(404);
    exit('پیوند نامعتبر است.');
}
if (!user_now()) {
    $_SESSION['reception_next'] = 'reception-payment.php?code=' . $code;
    redirect('login.php?next=reception-payment.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $id = reception_claim($code);
        $b = own_booking($id);
        redirect(($b['status'] === 'held' ? 'checkout.php' : 'receipt.php') . '?id=' . $id);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
layout_start('پیگیری پذیرش');
echo '<article class="card narrow"><h1>پیگیری نوبت ثبت‌شده در مطب</h1><p>با ادامه، نوبت دارای این کد و موبایل تأییدشده شما به حسابتان متصل می‌شود. برای رزرو خانواده، نام ثبت‌شده در پذیرش حفظ می‌شود.</p>';
if ($error) {
    echo '<p class="error">' . esc($error) . '</p>';
}
echo '<form method="post">';
form_token('claim');
echo '<button>ادامه و مشاهده نوبت</button></form></article>';
layout_end();
