<?php
require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=patient.php');
}
$id = (int) ($_GET['id'] ?? 0);
$error = '';
try {
    $b = own_booking($id);
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    layout_start('رسید');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        patient_change($_POST + ['id' => $id]);
        $_SESSION['flash'] = 'تغییر نوبت ثبت شد.';
        redirect('receipt.php?id=' . $id);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$b = own_booking($id);
$b += query('SELECT d.photo_file,sp.name specialty,ci.name city FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.id=? JOIN cities ci ON ci.id=cl.city_id WHERE d.id=?', [$b['clinic_id'], $b['doctor_id']])->fetch() ?: [];
$payment = query('SELECT * FROM payment_intents WHERE booking_id=?', [$id])->fetch();
$tx = query(
    "SELECT * FROM payment_transactions WHERE purpose='booking' AND reference_id=? AND status IN ('verified','refund_due','refunded') ORDER BY id DESC LIMIT 1",
    [$id],
)->fetch();
$events = query(
    'SELECT e.* FROM appointment_events e JOIN bookings b ON b.id=e.booking_id WHERE e.booking_id=? AND b.patient_user_id=? ORDER BY e.id DESC LIMIT 100',
    [$id, require_user()['id']],
)->fetchAll();
require __DIR__ . '/lib/doctor_views.php';
$fresh = $b['status'] === 'confirmed' && (isset($_GET['new']) || ($b['confirmed_at'] && strtotime($b['confirmed_at']) > time() - 900));
layout_start($fresh ? 'نوبت شما ثبت شد' : 'رسید رزرو', ['body_class' => 'booking-page receipt-page', 'robots' => 'noindex,nofollow']);
render('public/receipt', ['b' => $b, 'tx' => $tx, 'payment' => $payment, 'error' => $error, 'events' => $events, 'fresh' => $fresh]);
layout_end();
