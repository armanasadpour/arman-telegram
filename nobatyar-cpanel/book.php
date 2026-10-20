<?php
require __DIR__ . '/core.php';
require_phase2();
$id = (int) ($_GET['slot'] ?? ($_POST['slot'] ?? 0));
$sql =
    'SELECT s.*,d.name doctor,d.subscription_until,d.active,d.review_status,cl.name clinic,cl.address,cl.phone,c.name city,p.name province FROM slots s JOIN doctors d ON d.id=s.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.id=s.clinic_id AND cl.doctor_id=d.id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE s.id=? AND ' .
    visible_doctor_sql() .
    ' AND ' .
    public_clinic_sql();
$slot = query($sql, [$id])->fetch();
if (!$slot) {
    http_response_code(404);
    layout_start('نوبت یافت نشد');
    echo '<p>نوبت یا مطب در دسترس نیست.</p>';
    layout_end();
    exit();
}
$error = '';
$success = '';
$user = user_now();
if (!$user) {
    redirect('login.php?next=' . rawurlencode('book.php?slot=' . $id));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $name = field($_POST, 'name', 190);
        $phone = $user['mobile'];
        if (mb_strlen($name) < 3 || !preg_match('/^09[0-9]{9}$/', $phone)) {
            throw new RuntimeException('نام و شماره موبایل معتبر وارد کنید.');
        }
        throttle('booking', 20, 3600);
        $bid = hold_booking([
            'slot_id' => $id, 'name' => $name,
            'family_member_id' => (int) ($_POST['family_member_id'] ?? 0),
            'reminder_opt_in' => !empty($_POST['reminder_opt_in']),
        ]);
        if ((int) booking_row($bid)['fee_toman'] > 0) redirect('checkout.php?id=' . $bid);
        confirm_free($bid);
        $code = query('SELECT tracking_code FROM bookings WHERE id=?', [$bid])->fetchColumn();
        $_SESSION['booking_receipt'] = ['slot' => $id, 'code' => $code];
        redirect('book.php?slot=' . $id . '&done=1');
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        $error = error_message($e);
    }
}
if (isset($_GET['done']) && ($_SESSION['booking_receipt']['slot'] ?? 0) === $id) {
    $success = $_SESSION['booking_receipt']['code'];
    unset($_SESSION['booking_receipt']);
}
layout_start('رزرو نوبت');
echo '<h1>رزرو نوبت ' .
    esc(doctor_display_name($slot['doctor'])) .
    '</h1><p>' .
    esc($slot['clinic'] . ' | ' . $slot['province'] . '، ' . $slot['city'] . '، ' . $slot['address']) .
    '</p><p>زمان شمسی به وقت تهران: ' .
    esc(jdate($slot['starts_at'], true)) .
    '</p>';
if ($success) {
    echo '<article class="card"><h2>نوبت ثبت شد</h2><p>کد رهگیری: <strong>' .
        esc($success) .
        '</strong></p><p>هزینه ویزیت در مطب پرداخت می‌شود. پیامک اطلاع‌رسانی نوبت هنوز فعال نیست؛ کد در پنل شما نیز قابل مشاهده است.</p></article>';
} else {
    if ($error) {
        echo '<p class="error">' . esc($error) . '</p>';
    }
    if (strtotime($slot['starts_at']) <= time()) {
        echo '<p>زمان رزرو این نوبت گذشته است.</p>';
        layout_end();
        exit();
    }
    echo '<form method="post">';
    form_token('book');
    echo '<input type="hidden" name="slot" value="' . $id . '">';
    input('نام بیمار', 'name', $_POST['name'] ?? $user['name']);
    echo '<label>عضو خانواده (اختیاری)<select name="family_member_id"><option value="0">خودم</option>';
    foreach (
        query('SELECT id,name FROM family_members WHERE user_id=? AND active=1', [$user['id']])->fetchAll()
        as $f
    ) {
        echo '<option value="' . $f['id'] . '">' . esc($f['name']) . '</option>';
    }
    echo '</select></label>';
    booking_reminder_choice($slot['starts_at'], is_post() ? $_POST : []);
    echo '<p>موبایل تأییدشده: <bdi>' .
        esc(fa_number($user['mobile'])) .
        '</bdi></p><p>کارمزد این رزرو: ' .
        esc(toman(booking_fee_for((int) $slot['clinic_id'])[0])) .
        '</p><button>ثبت رزرو و ادامه</button></form>';
}
layout_end();
