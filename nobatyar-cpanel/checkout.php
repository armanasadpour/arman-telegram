<?php
declare(strict_types=1);

/**
 * Final booking step: fee breakdown, optional discount code, gateway choice and payment
 * (or free confirmation). Payments started before 0.9.0 can still be re-checked here.
 */

require __DIR__ . '/core.php';
require_schema();
if (!user_now()) {
    redirect('login.php?next=patient.php');
}
$id = (int) ($_GET['id'] ?? 0);
$error = '';
try {
    own_booking($id);
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    layout_start('رزرو');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}
if (is_post()) {
    verify_csrf();
    try {
        $action = field($_POST, 'action', 30);
        if ($action === 'confirm') {
            confirm_free($id);
            redirect('receipt.php?id=' . $id . '&new=1');
        } elseif ($action === 'pay') {
            if (!empty($_POST['use_wallet'])) { wallet_pay_booking($id); redirect('receipt.php?id='.$id.'&new=1'); }
            $gateway = (string) ($_POST['gateway'] ?? '');
            gateway_send(start_payment($id, null, $gateway !== '' ? $gateway : null));
        } elseif ($action === 'discount') {
            booking_apply_discount($id, (string) ($_POST['code'] ?? ''));
            flash('کد تخفیف اعمال شد.');
            redirect('checkout.php?id=' . $id);
        } elseif ($action === 'verify') {
            $pay = query('SELECT authority FROM payment_intents WHERE booking_id=?', [$id])->fetch();
            if (!$pay) {
                throw new AuthError('درخواست پرداخت ثبت نشده است.');
            }
            verify_payment($pay['authority']);
            redirect('receipt.php?id=' . $id . '&new=1');
        } elseif ($action === 'release') {
            patient_change(['id' => $id, 'revision' => $_POST['revision'] ?? 0, 'operation' => 'release']);
            redirect('patient.php?tab=past');
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$b = own_booking($id);
$gateways = usable_gateways();
$extra = query('SELECT d.photo_file,sp.name specialty,ci.name city,ds.visit_fee_toman FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.id=? JOIN cities ci ON ci.id=cl.city_id LEFT JOIN doctor_services ds ON ds.id=? WHERE d.id=?', [$b['clinic_id'], $b['service_id'], $b['doctor_id']])->fetch() ?: [];
render('public/checkout', [
    'b' => $b + $extra,
    'error' => $error,
    'open' => $b['status'] === 'held' && $b['expires_at'] > date('Y-m-d H:i:s'),
    'gateways' => $gateways,
    'defaultGateway' => payment_active_gateway(),
    'legacy' => (bool) query('SELECT id FROM payment_intents WHERE booking_id=? AND status NOT IN (\'verified\',\'refund_due\',\'refunded\')', [$id])->fetch(),
    'transactions' => query(
        "SELECT * FROM payment_transactions WHERE purpose='booking' AND reference_id=? ORDER BY id DESC LIMIT 5",
        [$id],
    )->fetchAll(),
]);
