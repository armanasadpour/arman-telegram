<?php
require __DIR__ . '/core.php';
require_phase2();
if (!user_now()) {
    redirect('login.php?next=secretary.php');
}
$id = (int) ($_GET['id'] ?? 0);
$error = '';
try {
    $b = reception_booking($id);
    $c = reception_scope((int) $b['clinic_id']);
    $a = doctor_actor((int) $b['doctor_id'], 'view', (int) $b['clinic_id'], true);
    $manage =
        $a['is_owner'] ||
        (bool) query(
            "SELECT can_manage_bookings FROM staff_scopes WHERE user_id=? AND clinic_id=? AND role='secretary'",
            [require_user()['id'], $b['clinic_id']],
        )->fetchColumn();
} catch (Throwable $e) {
    http_response_code($e instanceof AuthError ? $e->status : 422);
    layout_start('پذیرش');
    echo '<p class="error">' . esc(error_message($e)) . '</p>';
    layout_end();
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        reception_change($_POST + ['id' => $id]);
        $_SESSION['flash'] = 'وضعیت پذیرش ثبت شد.';
        redirect('secretary-receipt.php?id=' . $id);
    } catch (Throwable $e) {
        $error = error_message($e);
    }
}
$b = reception_booking($id);
layout_start('رسید پذیرش');
if ($error) {
    echo '<p class="error" role="alert">' . esc($error) . '</p>';
}
echo '<article class="card receipt"><span class="eyebrow">رسید پذیرش نوبت‌یار</span><h1>' .
    esc($b['patient_name']) .
    '</h1><p>' .
    esc($b['doctor'] . ' • ' . $b['clinic']) .
    '</p><p>' .
    esc(jdate($b['starts_at'], true) . ' • ' . $b['service_name']) .
    '</p><p>وضعیت: ' .
    esc(
        $b['status'] === 'held' && $b['expires_at'] <= date('Y-m-d H:i:s')
            ? 'منقضی‌شده'
            : attendance_label($b),
    ) .
    '</p><p>موبایل تماس: <bdi>' .
    esc(fa_number($b['phone'])) .
    '</bdi></p><p>' .
    esc($b['address']) .
    '</p><p>کارمزد: ' .
    toman($b['fee_toman']) .
    ((int) ($b['vat_toman'] ?? 0) > 0 ? ' + مالیات ' . toman((int) $b['vat_toman']) : '') .
    ' • هزینه ویزیت در مطب</p><p>کد رهگیری: <bdi class="tracking">' .
    esc(tracking_label($b['tracking_code'])) .
    '</bdi></p>';
if ($b['status'] === 'held') {
    echo '<p class="notice">نوبت قطعی نیست. مهلت پرداخت: ' . esc(jdate($b['expires_at'], true)) . '</p>';
}
echo '<button type="button" data-print hidden>چاپ رسید پذیرش</button></article><p class="no-print"><a href="secretary.php?clinic=' .
    $b['clinic_id'] .
    '">بازگشت به پنل منشی</a></p>';
if (in_array($b['booking_channel'], ['phone', 'in_person'], true)) {
    echo '<article class="card no-print"><h2>پیوند پیگیری بیمار</h2><p>این پیوند را به بیمار بدهید؛ فقط با ورود و تأیید موبایل تماس این رزرو قابل پیگیری است.</p><a class="share-link" data-reception-link href="reception-payment.php?code=' .
        esc($b['tracking_code']) .
        '">پیگیری و پرداخت بیمار</a><p class="share-link" data-reception-url></p></article>';
}
if ($manage && $b['status'] === 'confirmed') {
    echo '<article class="card no-print"><h2>تغییر وضعیت پذیرش</h2><form method="post">';
    form_token('change');
    echo '<input type="hidden" name="id" value="' .
        $id .
        '"><input type="hidden" name="revision" value="' .
        $b['revision'] .
        '"><label>وضعیت جدید<select name="operation">';
    $states = [
        'waiting' => ['arrived' => 'مراجعه‌کرده', 'no_show' => 'عدم مراجعه'],
        'arrived' => ['in_visit' => 'در حال ویزیت', 'no_show' => 'عدم مراجعه'],
        'in_visit' => ['completed' => 'تکمیل‌شده'],
    ];
    foreach (($states[$b['attendance_state']] ?? []) + ['cancelled' => 'لغوشده'] as $v => $label) {
        echo '<option value="' . $v . '">' . $label . '</option>';
    }
    echo '</select></label>';
    input('علت یا توضیح', 'reason', '');
    echo '<label><input type="checkbox" name="patient_contacted" value="1"> در صورت لغو، بیمار را مطلع کردم</label><button>ثبت وضعیت</button></form></article>';
    if ($b['attendance_state'] === 'waiting' && $b['starts_at'] > date('Y-m-d H:i:s')) {
        echo '<article class="card no-print"><h2>جابه‌جایی با بررسی ظرفیت</h2><p>زمان مقصد باید در برنامه کاری و ظرفیت آزاد باشد؛ بازه پر یا تعطیل رد می‌شود.</p><form method="post">';
        form_token('change');
        echo '<input type="hidden" name="id" value="' .
            $id .
            '"><input type="hidden" name="revision" value="' .
            $b['revision'] .
            '"><input type="hidden" name="operation" value="reschedule"><label>مطب مقصد<select name="target_clinic_id">';
        foreach (doctor_clinics_for((int) $b['doctor_id']) as $cl) {
            if ($a['is_owner'] || !empty($cl['can_manage_bookings'])) {
                echo '<option value="' . $cl['id'] . '">' . esc($cl['name']) . '</option>';
            }
        }
        echo '</select></label><label>خدمت<select name="service_id">';
        foreach (
            query('SELECT * FROM doctor_services WHERE doctor_id=? AND active=1', [
                $b['doctor_id'],
            ])->fetchAll()
            as $s
        ) {
            echo '<option value="' . $s['id'] . '">' . esc($s['name']) . '</option>';
        }
        echo '</select></label>';
        input('تاریخ و ساعت شمسی مقصد', 'starts_at', '');
        input('علت انتقال', 'reason', '');
        echo '<label><input type="checkbox" name="patient_contacted" value="1" required> بیمار را از زمان و مطب جدید مطلع کردم</label><button>ثبت جابه‌جایی</button></form></article>';
    }
}
if ($manage && $b['status'] === 'held') {
    echo '<form method="post" class="card no-print">';
    form_token('change');
    echo '<input type="hidden" name="id" value="' .
        $id .
        '"><input type="hidden" name="revision" value="' .
        $b['revision'] .
        '"><input type="hidden" name="operation" value="release">';
    input('علت آزادسازی', 'reason', '');
    echo '<button class="secondary">لغو رزرو موقت و آزادسازی ظرفیت</button></form>';
}
require __DIR__ . '/lib/doctor_views.php';
echo '<section class="no-print"><h2>تاریخچه پذیرش</h2>';
foreach (
    query(
        'SELECT e.*,u.name actor FROM appointment_events e LEFT JOIN users u ON u.id=e.actor_user_id WHERE booking_id=? ORDER BY e.id DESC LIMIT 100',
        [$id],
    )->fetchAll()
    as $e
) {
    echo '<p class="card">' .
        esc(
            event_label($e['event_type']) .
                ' • ' .
                ($e['actor'] ?: 'سامانه') .
                ' • ' .
                jdate($e['created_at'], true),
        ) .
        '</p>';
}
echo '</section>';
layout_end();
