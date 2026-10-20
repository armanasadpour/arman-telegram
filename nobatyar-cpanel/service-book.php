<?php
/**
 * Booking step 2 — who is the appointment for. Signed-in patients complete missing details once
 * (name, birth year, gender, city and, if the administrator requires it, the national ID), choose
 * themselves or a family member (or add one), and the chosen time is held for checkout.php.
 * A reschedule (move=ID) asks only for the reason. The time is re-checked on every visit.
 */
require __DIR__ . '/core.php';
require_phase2();
$clinic = (int) ($_GET['clinic'] ?? 0);
$service = (int) ($_GET['service'] ?? 0);
$start = field($_GET, 'at', 19, false);
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $start) || strtotime($start) === false) {
    $start = '';
}
$move = (int) ($_GET['move'] ?? 0);
$self = 'service-book.php?' . http_build_query(['clinic' => $clinic, 'service' => $service, 'move' => $move, 'at' => $start]);
if (!user_now()) {
    $_SESSION['booking_next'] = $self;
    redirect('login.php?next=service-book.php');
}
$u = require_user();
$error = '';
$c = query(
    'SELECT cl.*,d.name doctor,d.id doctor_id,d.photo_file,sp.name specialty,ci.name city FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN cities ci ON ci.id=cl.city_id WHERE cl.id=?',
    [$clinic],
)->fetch();
$svc = $c ? query('SELECT * FROM doctor_services WHERE id=? AND doctor_id=? AND plan_locked=0', [$service, $c['doctor_id']])->fetch() : null;
if (!$c || !$svc) {
    http_response_code(404);
    layout_start('نوبت پیدا نشد');
    echo '<div class="empty-state wide"><p>مطب یا خدمت پیدا نشد.</p><a class="button secondary" href="index.php?view=doctors">جستجوی پزشک</a></div>';
    layout_end();
    exit();
}
$old = null;
if ($move) {
    try {
        $old = own_booking($move);
        if ((int) $old['doctor_id'] !== (int) $c['doctor_id']) {
            throw new AuthError('پزشک مقصد نامعتبر است.', 403);
        }
    } catch (Throwable $e) {
        http_response_code(403);
        exit('دسترسی مجاز نیست.');
    }
}
$selectedMember = isset($_GET['for']) ? (int) $_GET['for'] : null;
if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'profile') {
            patient_profile_save($u, $_POST);
            flash('اطلاعات شما ذخیره شد.');
            redirect($self);
        } elseif ($action === 'family') {
            $editing = (int) ($_POST['id'] ?? 0) > 0;
            $new = family_member_save((int) $u['id'], $_POST);
            flash($editing ? 'اطلاعات مراجعه‌کننده ذخیره شد.' : 'مراجعه‌کننده جدید اضافه شد.');
            redirect($self . '&for=' . $new);
        } elseif ($action === 'reserve') {
            throttle('booking', 20, 3600);
            if ($old) {
                patient_change([
                    'id' => $move,
                    'revision' => $_POST['revision'] ?? 0,
                    'operation' => 'reschedule',
                    'reason' => field($_POST, 'reason', 500),
                    'clinic_id' => $clinic,
                    'service_id' => $service,
                    'starts_at' => $start,
                ]);
                redirect('receipt.php?id=' . $move);
            }
            if (patient_missing(user_now())) {
                throw new AuthError('ابتدا اطلاعات خود را تکمیل کنید.');
            }
            if (!isset($_POST['family_member_id']) || $_POST['family_member_id'] === '') {
                throw new AuthError('مشخص کنید نوبت برای چه کسی است.');
            }
            $memberId = (int) $_POST['family_member_id'];
            if ($memberId) {
                $member = query('SELECT * FROM family_members WHERE id=? AND user_id=? AND active=1', [$memberId, $u['id']])->fetch();
                if ($member && member_missing($member)) {
                    throw new AuthError('اطلاعات ' . $member['name'] . ' کامل نیست؛ «تکمیل اطلاعات» را بزنید و تاریخ تولد، جنسیت، نوع بیمه و کد ملی را ثبت کنید.');
                }
            }
            $bid = hold_booking([
                'clinic_id' => $clinic,
                'service_id' => $service,
                'starts_at' => $start,
                'name' => (string) user_now()['name'],
                'family_member_id' => (int) $_POST['family_member_id'],
                'reminder_opt_in' => !empty($_POST['reminder_opt_in']),
            ]);
            redirect('checkout.php?id=' . $bid);
        } else {
            throw new AuthError('عملیات نامعتبر است.');
        }
    } catch (Throwable $e) {
        $error = error_message($e);
        $selectedMember = isset($_POST['family_member_id']) && $_POST['family_member_id'] !== '' ? (int) $_POST['family_member_id'] : $selectedMember;
    }
}
$u = require_user();
// The chosen time is checked again on arrival: another patient may have taken it meanwhile.
try {
    $free = $start !== '' && in_array($start, array_column(availability($clinic, $service, substr($start, 0, 10), $move), 'starts_at'), true);
} catch (Throwable $e) {
    $free = false;
}
layout_start($move ? 'تغییر زمان نوبت' : 'اطلاعات مراجعه‌کننده', ['body_class' => 'booking-page', 'robots' => 'noindex,nofollow']);
render('public/booking-patient', [
    'u' => $u,
    'c' => $c,
    'svc' => $svc,
    'start' => $start,
    'move' => $move,
    'old' => $old,
    'free' => $free,
    'error' => $error,
    'post' => $error ? $_POST : [],
    'missing' => $move ? [] : patient_missing($u),
    'members' => query('SELECT * FROM family_members WHERE user_id=? AND active=1 ORDER BY id', [$u['id']])->fetchAll(),
    'selectedMember' => $selectedMember,
    'self' => $self,
    'fee' => booking_fee_for($clinic)[0],
]);
layout_end();
