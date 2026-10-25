<?php
declare(strict_types=1);
function number_range(array $data, string $name, int $min, int $max): int
{
    $s = digits(field($data, $name, 8));
    if (!preg_match('/^[0-9]+$/', $s) || (int) $s < $min || (int) $s > $max) {
        throw new AuthError('مقدار عددی باید بین ' . fa_number($min) . ' و ' . fa_number($max) . ' باشد.');
    }
    return (int) $s;
}
function doctor_actor(
    int $doctor,
    string $permission = 'view',
    int $clinic = 0,
    bool $allowPending = false,
): array {
    $u = require_user();
    $d = query('SELECT * FROM doctors WHERE id=?', [$doctor])->fetch();
    if (!$d) {
        throw new AuthError('پزشک پیدا نشد.', 404);
    }
    $owner =
        has_role('doctor') &&
        (bool) query('SELECT doctor_id FROM doctor_accounts WHERE doctor_id=? AND user_id=?', [
            $doctor,
            $u['id'],
        ])->fetch();
    if (!$allowPending && ($d['review_status'] !== 'approved' || !$d['active'])) {
        throw new AuthError('دسترسی عملیاتی پزشک فعال و تأییدشده لازم است.', 403);
    }
    if ($clinic && !query('SELECT id FROM clinics WHERE id=? AND doctor_id=?', [$clinic, $doctor])->fetch()) {
        throw new AuthError('مطب متعلق به این پزشک نیست.', 403);
    }
    if (!$owner) {
        if ($permission === 'owner' || !has_role('secretary')) {
            throw new AuthError('دسترسی پزشک لازم است.', 403);
        }
        $sql =
            "SELECT s.* FROM staff_scopes s JOIN clinics c ON c.id=s.clinic_id WHERE s.user_id=? AND s.role='secretary' AND c.doctor_id=? AND (s.center_id IS NULL OR EXISTS(SELECT 1 FROM centers ce WHERE ce.id=s.center_id AND " .
            center_open_sql('ce') .
            '))';
        $args = [$u['id'], $doctor];
        if ($clinic) {
            $sql .= ' AND c.id=?';
            $args[] = $clinic;
        }
        $scopes = query($sql, $args)->fetchAll();
        // rc.20: the doctor's own secretaries work only within the doctor's plan (Pro by default);
        // secretaries of a medical center are covered by the center's subscription.
        if ($scopes && !doctor_secretary_allowed($doctor, (int) $u['id'])) {
            $scopes = array_values(array_filter($scopes, fn($s) => $s['center_id'] !== null));
            if (!$scopes) {
                throw new AuthError('دسترسی منشی این پزشک با اشتراک پرو پزشک فعال است؛ پزشک باید اشتراک پرو را فعال یا تمدید کند.', 403);
            }
        }
        if (!$scopes) {
            throw new AuthError('به این پزشک یا مطب دسترسی ندارید.', 403);
        }
        $column =
            [
                'schedule' => 'can_manage_schedule',
                'bookings' => 'can_manage_bookings',
                'history' => 'can_view_history',
            ][$permission] ?? null;
        if ($column && (!$clinic || empty($scopes[0][$column]))) {
            throw new AuthError('این عملیات در سطح دسترسی منشی نیست.', 403);
        }
    }
    $d['is_owner'] = $owner;
    return $d;
}
function doctor_clinics_for(int $doctor): array
{
    $d = doctor_actor($doctor, 'view', 0, true);
    if ($d['is_owner']) {
        return query(
            'SELECT cl.*,c.province_id,c.name city FROM clinics cl JOIN cities c ON c.id=cl.city_id WHERE cl.doctor_id=? ORDER BY cl.is_primary DESC,cl.id',
            [$doctor],
        )->fetchAll();
    }
    return query(
        "SELECT cl.*,c.province_id,c.name city,s.can_manage_schedule,s.can_manage_bookings,s.can_view_history FROM clinics cl JOIN cities c ON c.id=cl.city_id JOIN staff_scopes s ON s.clinic_id=cl.id WHERE cl.doctor_id=? AND s.user_id=? AND s.role='secretary'",
        [$doctor, require_user()['id']],
    )->fetchAll();
}
function lock_doctor(int $id): void
{
    if (!query('SELECT id FROM doctors WHERE id=? FOR UPDATE', [$id])->fetch()) {
        throw new AuthError('پزشک پیدا نشد.', 404);
    }
}
function event_record(int $doctor, string $type, array $details = [], ?int $booking = null): void
{
    query(
        'INSERT INTO appointment_events(doctor_id,booking_id,actor_user_id,actor_admin_id,event_type,details) VALUES(?,?,?,?,?,?)',
        [
            $doctor,
            $booking,
            $_SESSION['user_id'] ?? null,
            $_SESSION['admin_id'] ?? null,
            $type,
            json_encode($details, JSON_UNESCAPED_UNICODE),
        ],
    );
    if (schema_is_current_safe()) {
        $eventId=(int)db()->lastInsertId();
        if (str_contains($type,'payment.verified') || $type==='subscription.paid') admin_event('payment','پرداخت موفق','پرداخت جدید در سامانه ثبت شد.','event:'.$eventId,'admin.php?tab=payments');
        elseif (in_array($type,['booking.created','booking.cancelled','booking.rescheduled','booking.reschedule'],true)) admin_event('booking','تغییر نوبت','یک نوبت در سامانه ثبت یا تغییر کرد.','event:'.$eventId);
    }
    // Any appointment or schedule event can change the doctor's first free time in search results.
    try {
        clinic_free_forget_doctor($doctor);
    } catch (Throwable $e) {
        // Cache table appears with migration 17; older schemas simply have nothing to forget.
    }
}
/** Persian label for appointment_events.event_type, used in histories and audit views. */
function event_label(string $type): string
{
    $labels = [
        'admin.clinic' => 'ویرایش مطب توسط مدیر',
        'admin.delete_clinic' => 'حذف مطب توسط مدیر',
        'admin.edit_doctor' => 'ویرایش اطلاعات پزشک توسط مدیر',
        'admin.review' => 'تغییر وضعیت بررسی',
        'admin.slot' => 'افزودن زمان نوبت',
        'admin.subscription' => 'ثبت اشتراک دستی',
        'booking.cancelled' => 'لغو نوبت',
        'booking.completed' => 'تکمیل ویزیت',
        'booking.created' => 'قطعی‌شدن نوبت',
        'booking.expired' => 'انقضای رزرو موقت',
        'booking.held' => 'رزرو موقت',
        'booking.no_show' => 'عدم مراجعه',
        'booking.released' => 'آزادسازی رزرو موقت',
        'booking.reschedule' => 'جابه‌جایی نوبت',
        'doctor.block' => 'بستن بازه زمانی',
        'doctor.block_remove' => 'بازکردن بازه زمانی',
        'doctor.clinic' => 'ویرایش مطب',
        'doctor.clinic_settings' => 'تغییر مدت، فاصله یا ظرفیت',
        'doctor.hours' => 'افزودن ساعت کاری',
        'doctor.hours_remove' => 'حذف ساعت کاری',
        'doctor.week' => 'تغییر برنامه هفتگی',
        'doctor.photo' => 'تغییر تصویر',
        'doctor.photo_remove' => 'حذف تصویر',
        'doctor.profile' => 'ویرایش پروفایل',
        'doctor.register' => 'ثبت‌نام پزشک',
        'doctor.service' => 'تعریف یا ویرایش خدمت',
        'doctor.staff' => 'تعریف منشی',
        'doctor.staff_remove' => 'حذف منشی',
        'patient.cancelled' => 'لغو توسط بیمار',
        'patient.reschedule' => 'تغییر زمان توسط بیمار',
        'payment.refund_due' => 'نیاز به بازگشت وجه',
        'payment.requested' => 'درخواست پرداخت',
        'payment.verified' => 'تأیید پرداخت',
        'reception.attendance' => 'تغییر وضعیت حضور',
        'reception.booked' => 'ثبت نوبت توسط پذیرش',
        'reception.claimed' => 'اتصال نوبت به حساب تأییدشده بیمار',
        'reception.contact' => 'ثبت تماس با بیمار',
        'reception.delay' => 'اعلان تأخیر پزشک',
        'reception.delay_created' => 'ثبت تأخیر و مخاطبان',
        'reception.patient_created' => 'ثبت بیمار پذیرش',
        'reception.released' => 'آزادسازی رزرو توسط پذیرش',
        'reception.sms_accepted' => 'پذیرش پیامک تأخیر توسط سرویس',
        'reception.sms_started' => 'شروع ارسال پیامک تأخیر',
        'reception.sms_unknown' => 'نتیجه نامشخص ارسال پیامک',
    ];
    if (isset($labels[$type])) {
        return $labels[$type];
    }
    return str_starts_with($type, 'reception.') ? 'عملیات پذیرش منشی' : 'رویداد نوبت';
}

/**
 * Queues the patient SMS for a booking event (confirmed / cancelled / rescheduled).
 * The dedupe key includes the booking revision, so one change never produces two messages.
 */
function booking_sms(int $booking, string $event): void
{
    $b = query(
        'SELECT b.id,b.phone,b.tracking_code,b.revision,b.patient_user_id,s.starts_at,d.name doctor FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN doctors d ON d.id=s.doctor_id WHERE b.id=?',
        [$booking],
    )->fetch();
    if (!$b || !preg_match('/^09[0-9]{9}$/', (string) $b['phone'])) {
        return;
    }
    $date = digits(jdate($b['starts_at']));
    $time = substr($b['starts_at'], 11, 5);
    $tokens = match ($event) {
        'booking_confirmed' => [
            'token' => preg_match('/^[0-9]{12}$/', (string) $b['tracking_code']) ? $b['tracking_code'] : substr($b['tracking_code'], 0, 8),
            'token2' => $date,
            'token3' => $time,
            'token10' => doctor_display_name((string) $b['doctor']),
        ],
        'booking_cancelled', 'booking_rescheduled' => ['token' => $date, 'token2' => $time, 'token10' => doctor_display_name((string) $b['doctor'])],
        default => null,
    };
    if ($tokens === null) {
        return;
    }
    try {
        sms_enqueue($event, $b['phone'], $tokens, $event . ':' . $b['id'] . ':' . $b['revision'], [
            'booking_id' => (int) $b['id'],
            'user_id' => $b['patient_user_id'] ? (int) $b['patient_user_id'] : null,
        ]);
    } catch (Throwable $e) {
        // A notification problem must never undo the booking change itself.
        error_log('nobatyar booking sms: ' . $e->getMessage());
    }
}

/** In-app (and push) notice about a booking to its patient, the doctor and the clinic's reception staff. */
function notify_booking(int $booking, string $title, string $message, string $category = 'booking'): void
{
    $b = query(
        'SELECT b.patient_user_id,s.doctor_id,s.clinic_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.id=?',
        [$booking],
    )->fetch();
    if (!$b) {
        return;
    }
    $ids = [];
    if ($b['patient_user_id']) {
        $ids[(int) $b['patient_user_id']] = 'receipt.php?id=' . $booking;
    }
    $owner = query('SELECT user_id FROM doctor_accounts WHERE doctor_id=?', [$b['doctor_id']])->fetchColumn();
    if ($owner) {
        $ids[(int) $owner] ??= 'doctor-panel.php?doctor=' . (int) $b['doctor_id'] . '&tab=bookings';
    }
    foreach (query("SELECT user_id FROM staff_scopes WHERE clinic_id=? AND role='secretary'", [$b['clinic_id']])->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        $ids[(int) $uid] ??= 'secretary.php?clinic=' . (int) $b['clinic_id'];
    }
    foreach ($ids as $uid => $url) {
        notify_user($uid, $title, $message, $booking, $category, $url);
    }
}
function active_booking_status_sql(): string
{
    return "('confirmed','completed','no_show')";
}
function booking_status_label(string $status): string
{
    return [
        'held' => 'رزرو موقت',
        'expired' => 'منقضی‌شده',
        'confirmed' => 'قطعی',
        'cancelled' => 'لغوشده',
        'completed' => 'تکمیل‌شده',
        'no_show' => 'عدم مراجعه',
    ][$status] ?? 'نامشخص';
}
function valid_date(string $day): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    if (!$d || $d->format('Y-m-d') !== $day) {
        throw new AuthError('تاریخ نامعتبر است.');
    }
    return $day;
}
function occupied(
    int $doctor,
    string $start,
    string $end,
    int $excludeBooking = 0,
    int $excludeSlot = 0,
): bool {
    return (bool) query(
        'SELECT b.id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND ' .
            occupying_sql() .
            ' AND b.id<>? AND s.id<>? AND s.starts_at<? AND DATE_ADD(COALESCE(s.ends_at,DATE_ADD(s.starts_at,INTERVAL 15 MINUTE)),INTERVAL s.gap_minutes MINUTE)>? LIMIT 1',
        [$doctor, $excludeBooking, $excludeSlot, $end, $start],
    )->fetch();
}
function blocked(int $doctor, int $clinic, string $start, string $end): bool
{
    return (bool) query(
        'SELECT id FROM schedule_blocks WHERE doctor_id=? AND active=1 AND (clinic_id IS NULL OR clinic_id=?) AND starts_at<? AND ends_at>? LIMIT 1',
        [$doctor, $clinic, $end, $start],
    )->fetch();
}
function clinic_day_count(int $clinic, string $day, int $exclude = 0): int
{
    return (int) query(
        'SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND b.id<>? AND ' .
            occupying_sql() .
            ' AND s.starts_at>=? AND s.starts_at<?',
        [$clinic, $exclude, $day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00'],
    )->fetchColumn();
}
function availability(int $clinic, int $service, string $day, int $excludeBooking = 0): array
{
    valid_date($day);
    if ($day < date('Y-m-d') || $day > date('Y-m-d', strtotime('+90 days'))) {
        return [];
    }
    $c = query(
        'SELECT cl.*,d.subscription_until FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.id=? AND ' .
            visible_doctor_sql() .
            ' AND ' .
            public_clinic_sql(),
        [$clinic],
    )->fetch();
    if (!$c || bookable_until($c['subscription_until']) < $day) {
        return [];
    }
    $svc = query('SELECT * FROM doctor_services WHERE id=? AND doctor_id=? AND active=1 AND plan_locked=0', [
        $service,
        $c['doctor_id'],
    ])->fetch();
    if (!$svc) {
        return [];
    }
    $dayCount = clinic_day_count($clinic, $day, $excludeBooking);
    if ($dayCount >= (int) $c['daily_capacity']) {
        return [];
    }
    $gap = (int) $c['gap_minutes'];
    $weekday = (int) date('w', strtotime($day));
    $out = [];
    foreach (
        query('SELECT * FROM working_hours WHERE clinic_id=? AND weekday=? AND active=1 AND (valid_from IS NULL OR valid_from<=?) AND (valid_until IS NULL OR valid_until>=?) ORDER BY opens_at', [
            $clinic,
            $weekday,
            $day,
            $day,
        ])->fetchAll()
        as $r
    ) {
        // A day-specific length replaces the clinic default; a service with its own length keeps it.
        $duration = (int) $svc['duration_minutes'] ?: ((int) ($r['slot_minutes'] ?? 0) ?: (int) $c['default_duration']);
        $end = strtotime($day . ' ' . $r['closes_at']);
        for (
            $at = strtotime($day . ' ' . $r['opens_at']);
            $at + $duration * 60 <= $end;
            $at += ($duration + $gap) * 60
        ) {
            if ($at <= time()) {
                continue;
            }
            $start = date('Y-m-d H:i:s', $at);
            $finish = date('Y-m-d H:i:s', $at + $duration * 60);
            $busyEnd = date('Y-m-d H:i:s', $at + ($duration + $gap) * 60);
            if (blocked((int) $c['doctor_id'], $clinic, $start, $finish)) {
                continue;
            }
            // Explicit legacy times reserve availability for their own published slot.
            if (
                query(
                    "SELECT id FROM slots WHERE doctor_id=? AND source='legacy' AND starts_at<? AND COALESCE(ends_at,DATE_ADD(starts_at,INTERVAL 15 MINUTE))>? LIMIT 1",
                    [$c['doctor_id'], $busyEnd, $start],
                )->fetch()
            ) {
                continue;
            }
            $seats = slot_seats(
                (int) $c['doctor_id'],
                $clinic,
                $service,
                $start,
                $finish,
                $gap,
                (int) $c['slot_capacity'],
                $excludeBooking,
            );
            if ($seats < 1) {
                continue;
            }
            $out[$start] = [
                'capacity' => (int) $c['slot_capacity'],
                'remaining' => min($seats, (int) $c['daily_capacity'] - $dayCount),
                'starts_at' => $start,
                'ends_at' => $finish,
                'label' => jdate($start, true),
                'duration' => $duration,
                'gap' => $gap,
                'service' => $svc['name'],
            ];
        }
    }
    return array_values($out);
}
function reserve_service(int $clinic, int $service, string $start, string $patientName): int
{
    $id = hold_booking([
        'clinic_id' => $clinic,
        'service_id' => $service,
        'starts_at' => $start,
        'name' => $patientName,
    ]);
    return confirm_free($id);
}
function legacy_slot_open(array $s, int $excludeBooking = 0): bool
{
    $end = $s['ends_at'] ?? date('Y-m-d H:i:s', strtotime($s['starts_at'] . ' +15 minutes'));
    $busy = date('Y-m-d H:i:s', strtotime($end . ' +' . (int) ($s['gap_minutes'] ?? 0) . ' minutes'));
    if (
        ($s['source'] ?? 'legacy') !== 'legacy' ||
        strtotime($s['starts_at']) <= time() ||
        (array_key_exists('subscription_until', $s) && substr($s['starts_at'], 0, 10) > (string) bookable_until($s['subscription_until'])) ||
        blocked((int) $s['doctor_id'], (int) $s['clinic_id'], $s['starts_at'], $end) ||
        occupied((int) $s['doctor_id'], $s['starts_at'], $busy, $excludeBooking, (int) $s['id'])
    ) {
        return false;
    }
    $used = (int) query(
        'SELECT COUNT(*) FROM bookings b WHERE slot_id=? AND b.id<>? AND ' . occupying_sql(),
        [$s['id'], $excludeBooking],
    )->fetchColumn();
    if ($used >= (int) $s['capacity']) {
        return false;
    }
    $capacity = (int) query('SELECT daily_capacity FROM clinics WHERE id=?', [
        $s['clinic_id'],
    ])->fetchColumn();
    return clinic_day_count((int) $s['clinic_id'], substr($s['starts_at'], 0, 10), $excludeBooking) <
        $capacity;
}
