<?php
declare(strict_types=1);
function occupying_sql(string $alias = 'b'): string
{
    return "($alias.status IN ('confirmed','completed','no_show') OR ($alias.status='held' AND $alias.expires_at>NOW()))";
}
function beneficiary(int $uid, int $member, string $name): string
{
    if ($member) {
        $f = query('SELECT name FROM family_members WHERE id=? AND user_id=? AND active=1', [
            $member,
            $uid,
        ])->fetch();
        if (!$f) {
            throw new AuthError('عضو خانواده در حساب شما فعال نیست.', 403);
        }
        return $f['name'];
    }
    $name = trim($name);
    if (mb_strlen($name) < 3 || mb_strlen($name) > 190) {
        throw new AuthError('نام بیمار معتبر نیست.');
    }
    return $name;
}
function family_action(array $data): void
{
    $u = require_user();
    db_transaction(function () use ($u, $data) {
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$u['id']]);
        $id = (int) ($data['id'] ?? 0);
        if (
            $id &&
            !query('SELECT id FROM family_members WHERE id=? AND user_id=?', [$id, $u['id']])->fetch()
        ) {
            throw new AuthError('عضو پیدا نشد.', 404);
        }
        if (($data['operation'] ?? 'save') === 'archive') {
            query('UPDATE family_members SET active=0 WHERE id=? AND user_id=?', [$id, $u['id']]);
            audit('family.archive', $id);
            return;
        }
        $name = field($data, 'name', 190);
        if (mb_strlen($name) < 3) {
            throw new AuthError('نام کامل وارد کنید.');
        }
        $relation = field($data, 'relationship', 40);
        if (!in_array($relation, ['همسر', 'فرزند', 'پدر', 'مادر', 'سایر'], true)) {
            throw new AuthError('نسبت نامعتبر است.');
        }
        $birth = field($data, 'birth_date', 10, false);
        $birth = $birth !== '' ? parse_jdate($birth) : null;
        if ($birth && $birth > date('Y-m-d')) {
            throw new AuthError('تاریخ تولد در آینده نیست.');
        }
        if (
            query('SELECT id FROM family_members WHERE user_id=? AND name=? AND active=1 AND id<>?', [
                $u['id'],
                $name,
                $id,
            ])->fetch()
        ) {
            throw new AuthError('این نام قبلاً در خانواده ثبت شده است.');
        }
        if (
            !$id &&
            (int) query('SELECT COUNT(*) FROM family_members WHERE user_id=? AND active=1', [
                $u['id'],
            ])->fetchColumn() >= 20
        ) {
            throw new AuthError('حداکثر ۲۰ عضو فعال مجاز است.');
        }
        if ($id) {
            query('UPDATE family_members SET name=?,relationship=?,birth_date=?,active=1 WHERE id=?', [
                $name,
                $relation,
                $birth,
                $id,
            ]);
        } else {
            query('INSERT INTO family_members(user_id,name,relationship,birth_date) VALUES(?,?,?,?)', [
                $u['id'],
                $name,
                $relation,
                $birth,
            ]);
            $id = (int) db()->lastInsertId();
        }
        audit('family.save', $id);
    });
}
function patient_limit(
    int $uid,
    int $member,
    int $doctor,
    string $start,
    string $end,
    int $exclude = 0,
    bool $newHold = false,
): void {
    // Current locking read avoids a stale transaction snapshot during staff rescheduling.
    $rows = query(
        "SELECT b.id,b.family_member_id,b.status,s.doctor_id,s.starts_at,COALESCE(s.ends_at,DATE_ADD(s.starts_at,INTERVAL 15 MINUTE)) ends_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.patient_user_id=? AND b.id<>? AND (b.status='confirmed' OR (b.status='held' AND b.expires_at>NOW())) FOR UPDATE",
        [$uid, $exclude],
    )->fetchAll();
    $future = array_filter($rows, fn($r) => $r['starts_at'] > date('Y-m-d H:i:s'));
    if (count($future) >= (int) setting('max_active_bookings', '5')) {
        throw new AuthError('سقف نوبت‌های فعال این حساب تکمیل شده است.', 409);
    }
    foreach ($rows as $r) {
        if ($newHold && $r['status'] === 'held') {
            throw new AuthError('یک رزرو موقت فعال دارید؛ ابتدا آن را تأیید یا آزاد کنید.', 409);
        }
        if (
            (int) $r['family_member_id'] === $member &&
            (($r['starts_at'] < $end && $r['ends_at'] > $start) ||
                ((int) $r['doctor_id'] === $doctor &&
                    substr($r['starts_at'], 0, 10) === substr($start, 0, 10)))
        ) {
            throw new AuthError('برای این بیمار نوبت متداخل یا نوبت همان پزشک در این روز ثبت شده است.', 409);
        }
    }
}
function slot_seats(
    int $doctor,
    int $clinic,
    int $service,
    string $start,
    string $end,
    int $gap,
    int $capacity,
    int $exclude = 0,
): int {
    $busy = date('Y-m-d H:i:s', strtotime($end . ' +' . $gap . ' minutes'));
    $rows = query(
        'SELECT s.*,b.id booking_id FROM slots s JOIN bookings b ON b.slot_id=s.id WHERE s.doctor_id=? AND b.id<>? AND ' .
            occupying_sql() .
            ' AND s.starts_at<? AND DATE_ADD(COALESCE(s.ends_at,DATE_ADD(s.starts_at,INTERVAL 15 MINUTE)),INTERVAL s.gap_minutes MINUTE)>?',
        [$doctor, $exclude, $busy, $start],
    )->fetchAll();
    $used = 0;
    foreach ($rows as $r) {
        if (
            $r['source'] !== 'schedule' ||
            (int) $r['clinic_id'] !== $clinic ||
            (int) $r['service_id'] !== $service ||
            $r['starts_at'] !== $start ||
            $r['ends_at'] !== $end ||
            (int) $r['gap_minutes'] !== $gap
        ) {
            return 0;
        }
        $capacity = min($capacity, (int) $r['capacity']);
        $used++;
    }
    return max(0, $capacity - $used);
}
function expire_holds(int $uid = 0, int $doctor = 0): void
{
    // Lazy expiry; occupancy always checks expires_at, so capacity release never depends on cron.
    $args = [];
    $where = "b.status='held' AND b.expires_at<=NOW()";
    if ($uid) {
        $where .= ' AND b.patient_user_id=?';
        $args[] = $uid;
    }
    if ($doctor) {
        $where .= ' AND s.doctor_id=?';
        $args[] = $doctor;
    }
    foreach (
        query(
            'SELECT b.id,b.patient_user_id,s.doctor_id,s.clinic_id,b.expires_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE ' .
                $where .
                ' LIMIT 100',
            $args,
        )->fetchAll()
        as $b
    ) {
        $expire = function () use ($b) {
            lock_doctor((int) $b['doctor_id']);
            if ($b['patient_user_id']) {
                query('SELECT id FROM users WHERE id=? FOR UPDATE', [$b['patient_user_id']]);
            }
            $q = query(
                "UPDATE bookings SET status='expired',revision=revision+1 WHERE id=? AND status='held' AND expires_at<=NOW()",
                [$b['id']],
            );
            if ($q->rowCount()) {
                discount_release('booking', (int) $b['id']);
                waitlist_signal((int) $b['clinic_id']);
                event_record(
                    (int) $b['doctor_id'],
                    'booking.expired',
                    ['expires_at' => $b['expires_at']],
                    (int) $b['id'],
                );
            }
        };
        if (db()->inTransaction()) {
            $expire();
        } else {
            db_transaction($expire);
        }
    }
}
/** A new unique 12-digit tracking number (never starts with 0; old-format codes are checked too). */
function new_tracking_code(): string
{
    for ($i = 0; $i < 20; $i++) {
        $code = (string) random_int(100000000000, 999999999999);
        if (!query('SELECT 1 FROM bookings WHERE tracking_code=? OR legacy_tracking_code=? LIMIT 1', [$code, $code])->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('ساخت کد رهگیری ممکن نشد؛ دوباره تلاش کنید.');
}

/** «۴۸۲۹ ۱۰۳۸ ۵۵۱۲» for reading; codes from before 1.0.0-rc.7 are shown as they are. */
function tracking_label(string $code): string
{
    return preg_match('/^[0-9]{12}$/', $code) ? fa_number(implode(' ', str_split($code, 4))) : $code;
}

function hold_booking(array $data): int
{
    $u = require_user();
    $clinic = (int) ($data['clinic_id'] ?? 0);
    $legacy = (int) ($data['slot_id'] ?? 0);
    $service = (int) ($data['service_id'] ?? 0);
    $start = field($data, 'starts_at', 19, false);
    $member = (int) ($data['family_member_id'] ?? 0);
    $raw = $legacy ? query("SELECT * FROM slots WHERE id=? AND source='legacy'", [$legacy])->fetch() : null;
    if ($legacy && !$raw) {
        throw new AuthError('زمان قبلی پیدا نشد.', 404);
    }
    if ($raw) {
        $clinic = (int) $raw['clinic_id'];
        $start = $raw['starts_at'];
    }
    $c = query('SELECT * FROM clinics WHERE id=?', [$clinic])->fetch();
    if (!$c) {
        throw new AuthError('مطب پیدا نشد.', 404);
    }
    return db_transaction(function () use ($u, $data, $c, $clinic, $service, $start, $member, $raw) {
        lock_doctor((int) $c['doctor_id']);
        lock_contact($u['mobile']);
        $user = query('SELECT * FROM users WHERE id=? AND active=1 AND session_version=? FOR UPDATE', [
            $u['id'],
            $u['session_version'],
        ])->fetch();
        if (!$user) {
            throw new AuthError('نشست معتبر نیست.', 401);
        }
        expire_holds((int) $u['id'], (int) $c['doctor_id']);
        $name = beneficiary((int) $u['id'], $member, field($data, 'name', 190, false) ?: $u['name']);
        [$fee, $feeSource] = booking_fee_for($clinic);
        $reminder = !empty($data['reminder_opt_in']) && booking_reminder_eligible($start);
        $reminderFee = $reminder ? booking_reminder_fee() : 0;
        $reminderHours = booking_reminder_hours();
        $fee += $reminderFee;
        if ($fee > 0 && !payment_available()) {
            $wallet = wallet_account('user', (int)$u['id']);
            if ((int)$wallet['balance'] < $fee + vat_amount($fee, vat_percent())) {
                throw new AuthError('درگاه پرداخت آماده نیست و موجودی کیف پول برای پرداخت کافی نیست.', 503);
            }
        }
        if ($raw) {
            $slot = query(
                'SELECT s.*,d.subscription_until FROM slots s JOIN doctors d ON d.id=s.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.id=s.clinic_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE s.id=? AND ' .
                    visible_doctor_sql() .
                    ' AND ' .
                    public_clinic_sql(),
                [$raw['id']],
            )->fetch();
            if (!$slot || !legacy_slot_open($slot)) {
                throw new AuthError('این زمان یا ظرفیت قابل رزرو نیست.', 409);
            }
            $end = $slot['ends_at'] ?? date('Y-m-d H:i:s', strtotime($start . ' +15 minutes'));
        } else {
            $match = null;
            foreach (availability($clinic, $service, substr($start, 0, 10)) as $a) {
                if ($a['starts_at'] === $start) {
                    $match = $a;
                }
            }
            if (!$match) {
                throw new AuthError('ظرفیت این زمان دیگر در دسترس نیست.', 409);
            }
            $end = $match['ends_at'];
        }
        patient_limit((int) $u['id'], $member, (int) $c['doctor_id'], $start, $end, 0, true);
        contact_limit($u['mobile'], $name, (int) $c['doctor_id'], $start, $end);
        if (!$raw) {
            $slot = capacity_slot((int) $c['doctor_id'], $clinic, $service, $start);
        }
        $expires = date(
            'Y-m-d H:i:s',
            min(time() + max(1, min(30, (int) setting('hold_minutes', '10'))) * 60, strtotime($start)),
        );
        query(
            "INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,patient_user_id,family_member_id,status,expires_at,fee_toman,fee_source) VALUES(?,?,?,?,?,?,'held',?,?,?)",
            [
                $slot['id'],
                $name,
                $u['mobile'],
                new_tracking_code(),
                $u['id'],
                $member ?: null,
                $expires,
                $fee,
                $feeSource,
            ],
        );
        $bid = (int) db()->lastInsertId();
        query('UPDATE bookings SET reminder_opt_in=?,reminder_hours_snapshot=?,reminder_fee_toman=? WHERE id=?', [(int)$reminder, $reminderHours, $reminderFee, $bid]);
        event_record(
            (int) $c['doctor_id'],
            'booking.held',
            ['starts_at' => $start, 'expires_at' => $expires, 'fee_toman' => $fee],
            $bid,
        );
        return $bid;
    });
}
function booking_row(int $id): array
{
    $b = query(
        'SELECT b.*,s.doctor_id,s.clinic_id,s.starts_at,s.ends_at,s.service_id,s.service_name,s.gap_minutes,s.source,s.capacity,cl.name clinic,cl.address,cl.phone clinic_phone,d.name doctor FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN doctors d ON d.id=s.doctor_id WHERE b.id=?',
        [$id],
    )->fetch();
    if (!$b) {
        throw new AuthError('نوبت پیدا نشد.', 404);
    }
    return $b;
}
function own_booking(int $id): array
{
    $u = require_user();
    $b = booking_row($id);
    if ((int) $b['patient_user_id'] !== (int) $u['id']) {
        throw new AuthError('به این نوبت دسترسی ندارید.', 403);
    }
    return $b;
}
function hold_still_valid(array $b): bool
{
    if ($b['status'] !== 'held' || $b['expires_at'] <= date('Y-m-d H:i:s')) {
        return false;
    }
    if ($b['source'] === 'legacy') {
        $s = query(
            'SELECT s.*,d.subscription_until FROM slots s JOIN doctors d ON d.id=s.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.id=s.clinic_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE s.id=? AND ' .
                visible_doctor_sql() .
                ' AND ' .
                public_clinic_sql(),
            [$b['slot_id']],
        )->fetch();
        return $s && legacy_slot_open($s, (int) $b['id']);
    }
    foreach (
        availability(
            (int) $b['clinic_id'],
            (int) $b['service_id'],
            substr($b['starts_at'], 0, 10),
            (int) $b['id'],
        )
        as $a
    ) {
        if (
            $a['starts_at'] === $b['starts_at'] &&
            $a['ends_at'] === $b['ends_at'] &&
            (int) $a['gap'] === (int) $b['gap_minutes']
        ) {
            return true;
        }
    }
    return false;
}
function confirm_held(array $b): void
{
    query("UPDATE bookings SET status='confirmed',confirmed_at=NOW(),revision=revision+1 WHERE id=?", [
        $b['id'],
    ]);
    event_record(
        (int) $b['doctor_id'],
        'booking.created',
        ['slot_id' => $b['slot_id'], 'starts_at' => $b['starts_at']],
        (int) $b['id'],
    );
    notify_booking((int) $b['id'], 'نوبت قطعی شد', jdate($b['starts_at'], true) . ' • ' . $b['clinic']);
    booking_sms((int) $b['id'], 'booking_confirmed');
}
function confirm_free(int $id): int
{
    $old = own_booking($id);
    return db_transaction(function () use ($old, $id) {
        lock_doctor((int) $old['doctor_id']);
        lock_contact($old['phone']);
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$old['patient_user_id']]);
        $u = require_user();
        $b = own_booking($id);
        if ($b['status'] === 'confirmed') {
            return $id;
        }
        if ((int) $b['fee_toman'] !== 0) {
            throw new AuthError('این رزرو به تأیید پرداخت سرور نیاز دارد.', 409);
        }
        if (!hold_still_valid($b)) {
            throw new AuthError(
                'مهلت رزرو تمام شده یا برنامه پزشک تغییر کرده است؛ زمان تازه انتخاب کنید.',
                409,
            );
        }
        patient_limit(
            (int) $u['id'],
            (int) $b['family_member_id'],
            (int) $b['doctor_id'],
            $b['starts_at'],
            $b['ends_at'] ?? $b['starts_at'],
            $id,
        );
        confirm_held($b);
        query("UPDATE discount_redemptions SET status='used' WHERE order_type='booking' AND order_id=? AND status='reserved'", [$id]);
        return $id;
    });
}
function patient_change(array $data): void
{
    $id = positive_id($data['id'] ?? 0);
    $old = own_booking($id);
    db_transaction(function () use ($old, $id, $data) {
        lock_doctor((int) $old['doctor_id']);
        lock_contact($old['phone']);
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$old['patient_user_id']]);
        $u = require_user();
        $b = own_booking($id);
        $op = field($data, 'operation', 20);
        if ((int) $b['revision'] !== (int) ($data['revision'] ?? 0)) {
            throw new AuthError('نوبت تغییر کرده است؛ صفحه را تازه کنید.', 409);
        }
        if ($op === 'release') {
            if ($b['status'] !== 'held') {
                throw new AuthError('رزرو موقت فعال نیست.', 409);
            }
            query("UPDATE bookings SET status='cancelled',revision=revision+1 WHERE id=?", [$id]);
            discount_release('booking', $id);
            waitlist_signal((int) $b['clinic_id']);
            event_record((int) $b['doctor_id'], 'booking.released', [], $id);
            return;
        }
        if ($b['status'] !== 'confirmed' || $b['attendance_state'] !== 'waiting') {
            throw new AuthError('فقط نوبت قطعی پیش از پذیرش قابل تغییر است.', 409);
        }
        $kind = $op === 'cancelled' ? 'cancel' : ($op === 'reschedule' ? 'move' : '');
        if (!$kind) {
            throw new AuthError('عملیات نامعتبر است.');
        }
        if (
            setting('patient_' . $kind . '_enabled', '1') !== '1' ||
            strtotime($b['starts_at']) <= time() + (int) setting('patient_' . $kind . '_hours', '24') * 3600
        ) {
            throw new AuthError('مهلت مجاز این تغییر تمام شده است؛ با مطب تماس بگیرید.', 409);
        }
        $reason = field($data, 'reason', 500);
        $before = [
            'slot_id' => $b['slot_id'],
            'starts_at' => $b['starts_at'],
            'clinic_id' => $b['clinic_id'],
        ];
        if ($op === 'reschedule') {
            $clinic = positive_id($data['clinic_id'] ?? 0);
            $service = positive_id($data['service_id'] ?? 0);
            $c = query('SELECT id FROM clinics WHERE id=? AND doctor_id=?', [
                $clinic,
                $b['doctor_id'],
            ])->fetch();
            if (!$c) {
                throw new AuthError('انتقال فقط میان مطب‌های همین پزشک مجاز است.', 403);
            }
            $start = field($data, 'starts_at', 19);
            $match = null;
            foreach (availability($clinic, $service, substr($start, 0, 10), $id) as $a) {
                if ($a['starts_at'] === $start) {
                    $match = $a;
                }
            }
            if (!$match) {
                throw new AuthError('زمان جدید آزاد نیست.', 409);
            }
            patient_limit(
                (int) $u['id'],
                (int) $b['family_member_id'],
                (int) $b['doctor_id'],
                $start,
                $match['ends_at'],
                $id,
            );
            contact_limit(
                $b['phone'],
                $b['patient_name'],
                (int) $b['doctor_id'],
                $start,
                $match['ends_at'],
                $id,
            );
            query(
                "INSERT INTO slots(doctor_id,clinic_id,starts_at,ends_at,capacity,service_id,service_name,gap_minutes,source) VALUES(?,?,?,?,?,?,?,?,'schedule')",
                [
                    $b['doctor_id'],
                    $clinic,
                    $start,
                    $match['ends_at'],
                    $match['capacity'],
                    $service,
                    $match['service'],
                    $match['gap'],
                ],
            );
            $slot = (int) db()->lastInsertId();
            query('UPDATE bookings SET slot_id=?,revision=revision+1 WHERE id=?', [$slot, $id]);
            $after = ['slot_id' => $slot, 'starts_at' => $start, 'clinic_id' => $clinic];
        } else {
            query("UPDATE bookings SET status='cancelled',revision=revision+1 WHERE id=?", [$id]);
            booking_refund_on_cancel($id, $reason);
            waitlist_signal((int) $b['clinic_id']);
            $after = ['status' => 'cancelled'];
        }
        event_record(
            (int) $b['doctor_id'],
            'patient.' . $op,
            ['before' => $before, 'after' => $after, 'reason' => $reason],
            $id,
        );
        booking_sms($id, $op === 'cancelled' ? 'booking_cancelled' : 'booking_rescheduled');
        notify_booking(
            $id,
            $op === 'cancelled' ? 'نوبت توسط بیمار لغو شد' : 'بیمار زمان نوبت را تغییر داد',
            $reason . ($op === 'reschedule' ? ' • ' . jdate($after['starts_at'], true) : ''),
        );
    });
}
