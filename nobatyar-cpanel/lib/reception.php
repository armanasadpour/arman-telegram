<?php
declare(strict_types=1);
function reception_clinics(): array
{
    $u = require_user();
    return query(
        "SELECT DISTINCT c.*,d.name doctor FROM clinics c JOIN doctors d ON d.id=c.doctor_id LEFT JOIN staff_scopes sc ON sc.clinic_id=c.id AND sc.user_id=? AND sc.role='secretary' LEFT JOIN doctor_accounts da ON da.doctor_id=d.id AND da.user_id=? WHERE sc.user_id IS NOT NULL OR da.user_id IS NOT NULL ORDER BY d.name,c.name",
        [$u['id'], $u['id']],
    )->fetchAll();
}
function reception_scope(int $clinic, string $permission = 'view'): array
{
    $c = query('SELECT * FROM clinics WHERE id=?', [$clinic])->fetch();
    if (!$c) {
        throw new AuthError('مطب پیدا نشد.', 404);
    }
    doctor_actor((int) $c['doctor_id'], $permission, $clinic, true);
    return $c;
}
function reception_booking(int $id, string $permission = 'view'): array
{
    $b = booking_row($id);
    reception_scope((int) $b['clinic_id'], $permission);
    if ($b['starts_at'] < date('Y-m-d') . ' 00:00:00') {
        reception_scope((int) $b['clinic_id'], 'history');
    }
    return $b;
}
function attendance_label(array $b): string
{
    if ($b['status'] !== 'confirmed') {
        return booking_status_label($b['status']);
    }
    return ['waiting' => 'در انتظار', 'arrived' => 'مراجعه‌کرده', 'in_visit' => 'در حال ویزیت'][
        $b['attendance_state']
    ] ?? 'در انتظار';
}
function reception_patient(array $data): int
{
    $clinic = positive_id($data['clinic_id'] ?? 0);
    $c = reception_scope($clinic, 'bookings');
    $name = field($data, 'name', 190);
    if (mb_strlen($name) < 3) {
        throw new AuthError('نام بیمار را کامل وارد کنید.');
    }
    $parent = (int) ($data['parent_id'] ?? 0);
    return db_transaction(function () use ($clinic, $c, $name, $parent, $data) {
        lock_doctor((int) $c['doctor_id']);
        reception_scope($clinic, 'bookings');
        $mobile = normalize_mobile(field($data, 'mobile', 20));
        if ($parent) {
            $p = query('SELECT * FROM reception_patients WHERE id=? AND clinic_id=? AND parent_id IS NULL', [
                $parent,
                $clinic,
            ])->fetch();
            if (!$p) {
                throw new AuthError('سرپرست در این مطب پیدا نشد.', 403);
            }
            if ($p['mobile'] !== $mobile) {
                throw new AuthError('موبایل عضو خانواده باید موبایل سرپرست باشد.');
            }
        }
        // 1.0.0-rc.11: optional details for the daily Excel list (validated the same way as online patients).
        $gender = in_array($data['gender'] ?? '', ['male', 'female'], true) ? $data['gender'] : null;
        $birth = birth_from_input($data, false);
        $insurance = insurance_from_input($data, false);
        $ident = patient_identity($data, false);
        $city = (int) ($data['city_id'] ?? 0) ? city_row((int) $data['city_id']) : null;
        query(
            'INSERT INTO reception_patients(clinic_id,parent_id,name,mobile,created_by,gender,birth_date,id_type,national_id_enc,insurance,city_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
            [$clinic, $parent ?: null, $name, $mobile, require_user()['id'], $gender, $birth, $ident['id_type'], $ident['enc'], $insurance, $city['id'] ?? null],
        );
        $id = (int) db()->lastInsertId();
        event_record((int) $c['doctor_id'], 'reception.patient_created', [
            'clinic_id' => $clinic,
            'patient_id' => $id,
            'parent_id' => $parent,
        ]);
        return $id;
    });
}
function reception_search(int $clinic, string $term): array
{
    reception_scope($clinic, 'bookings');
    $term = digits(trim($term));
    if (mb_strlen($term) < 2 || mb_strlen($term) > 190) {
        throw new AuthError('حداقل دو حرف یا رقم جستجو کنید.');
    }
    $patients = query(
        'SELECT id,name,mobile,parent_id FROM reception_patients WHERE clinic_id=? AND (name LIKE ? OR mobile LIKE ?) ORDER BY name LIMIT 50',
        [$clinic, '%' . $term . '%', '%' . $term . '%'],
    )->fetchAll();
    // Search existing online visits only inside this clinic; never expose a global user directory.
    $c = reception_scope($clinic);
    $actor = doctor_actor((int) $c['doctor_id'], 'view', $clinic, true);
    $history =
        $actor['is_owner'] ||
        (bool) query(
            "SELECT can_view_history FROM staff_scopes WHERE user_id=? AND clinic_id=? AND role='secretary'",
            [require_user()['id'], $clinic],
        )->fetchColumn();
    $online = query(
        'SELECT DISTINCT b.patient_name name,b.phone mobile FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND (b.patient_name LIKE ? OR b.phone LIKE ?)' .
            ($history ? '' : ' AND s.starts_at>=CURDATE()') .
            ' ORDER BY b.patient_name LIMIT 50',
        [$clinic, '%' . $term . '%', '%' . $term . '%'],
    )->fetchAll();
    return ['patients' => $patients, 'visitors' => $online];
}
function lock_contact(string $mobile): void
{
    query('INSERT IGNORE INTO reservation_contacts(mobile) VALUES(?)', [$mobile]);
    query('SELECT mobile FROM reservation_contacts WHERE mobile=? FOR UPDATE', [$mobile]);
}
function contact_limit(
    string $mobile,
    string $name,
    int $doctor,
    string $start,
    string $end,
    int $exclude = 0,
): void {
    $rows = query(
        "SELECT b.patient_name,s.doctor_id,s.starts_at,COALESCE(s.ends_at,DATE_ADD(s.starts_at,INTERVAL 15 MINUTE)) ends_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.phone=? AND b.id<>? AND s.starts_at>NOW() AND (b.status='confirmed' OR (b.status='held' AND b.expires_at>NOW())) FOR UPDATE",
        [$mobile, $exclude],
    )->fetchAll();
    if (count($rows) >= (int) setting('max_active_bookings', '5')) {
        throw new AuthError('سقف نوبت‌های فعال این شماره تکمیل شده است.', 409);
    }
    foreach ($rows as $r) {
        if (
            trim($r['patient_name']) === trim($name) &&
            (($r['starts_at'] < $end && $r['ends_at'] > $start) ||
                ((int) $r['doctor_id'] === $doctor &&
                    substr($r['starts_at'], 0, 10) === substr($start, 0, 10)))
        ) {
            throw new AuthError('برای این بیمار نوبت متداخل یا تکراری ثبت شده است.', 409);
        }
    }
}
// Shared by online and reception bookings, under the same doctor row lock.
function capacity_slot(int $doctor, int $clinic, int $service, string $start, int $exclude = 0): array
{
    $match = null;
    foreach (availability($clinic, $service, substr($start, 0, 10), $exclude) as $a) {
        if ($a['starts_at'] === $start) {
            $match = $a;
        }
    }
    if (!$match) {
        throw new AuthError('ظرفیت این زمان دیگر در دسترس نیست.', 409);
    }
    query(
        "INSERT INTO slots(doctor_id,clinic_id,starts_at,ends_at,capacity,service_id,service_name,gap_minutes,source) VALUES(?,?,?,?,?,?,?,?,'schedule')",
        [
            $doctor,
            $clinic,
            $start,
            $match['ends_at'],
            $match['capacity'],
            $service,
            $match['service'],
            $match['gap'],
        ],
    );
    $match['id'] = (int) db()->lastInsertId();
    return $match;
}
function reception_reserve(array $data): int
{
    $clinic = positive_id($data['clinic_id'] ?? 0);
    $c = reception_scope($clinic, 'bookings');
    require_feature((int) $c['doctor_id'], 'reception'); // rc.20: free by default; Pro-only if the admin ticks it
    $patient = positive_id($data['patient_id'] ?? 0);
    $legacy = (int) ($data['slot_id'] ?? 0);
    $service = $legacy ? 0 : positive_id($data['service_id'] ?? 0);
    $start = field($data, 'starts_at', 19, false);
    $channel = field($data, 'channel', 20);
    if (!in_array($channel, ['phone', 'in_person'], true)) {
        throw new AuthError('روش پذیرش نامعتبر است.');
    }
    return db_transaction(function () use ($clinic, $c, $patient, $service, $start, $channel, $legacy) {
        $doc = (int) $c['doctor_id'];
        lock_doctor($doc);
        $p = query('SELECT * FROM reception_patients WHERE id=? AND clinic_id=?', [
            $patient,
            $clinic,
        ])->fetch();
        if (!$p) {
            throw new AuthError('بیمار در این مطب پیدا نشد.', 403);
        }
        lock_contact($p['mobile']);
        reception_scope($clinic, 'bookings');
        $policy = setting('secretary_fee_policy', 'exempt');
        if (!in_array($policy, ['exempt', 'online'], true)) {
            throw new AuthError('سیاست کارمزد تنظیم نشده است.', 503);
        }
        $fee = $policy === 'online' ? booking_fee_for($clinic)[0] : 0;
        if ($fee > 0 && !payment_available()) {
            throw new AuthError('درگاه پرداخت آماده نیست.', 503);
        }
        if ($legacy) {
            $slot = query(
                'SELECT s.*,d.subscription_until FROM slots s JOIN doctors d ON d.id=s.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN clinics cl ON cl.id=s.clinic_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE s.id=? AND s.clinic_id=? AND s.source=\'legacy\' AND ' .
                    visible_doctor_sql() .
                    ' AND ' .
                    public_clinic_sql(),
                [$legacy, $clinic],
            )->fetch();
            if (!$slot || !legacy_slot_open($slot)) {
                throw new AuthError('ظرفیت زمان دستی قابل رزرو نیست.', 409);
            }
            $start = $slot['starts_at'];
            $slot['ends_at'] = $slot['ends_at'] ?? date('Y-m-d H:i:s', strtotime($start . ' +15 minutes'));
        } else {
            $slot = capacity_slot($doc, $clinic, $service, $start);
        }
        contact_limit($p['mobile'], $p['name'], $doc, $start, $slot['ends_at']);
        $expiry = date(
            'Y-m-d H:i:s',
            min(time() + max(1, min(30, (int) setting('hold_minutes', '10'))) * 60, strtotime($start)),
        );
        $status = $fee > 0 ? 'held' : 'confirmed';
        query(
            'INSERT INTO bookings(slot_id,patient_name,phone,tracking_code,status,expires_at,fee_toman,confirmed_at,intake_patient_id,created_by_user_id,booking_channel,reception_fee_policy) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',
            [
                $slot['id'],
                $p['name'],
                $p['mobile'],
                new_tracking_code(),
                $status,
                $fee > 0 ? $expiry : null,
                $fee,
                $fee > 0 ? null : date('Y-m-d H:i:s'),
                $patient,
                require_user()['id'],
                $channel,
                $policy,
            ],
        );
        $id = (int) db()->lastInsertId();
        event_record(
            $doc,
            'reception.booked',
            [
                'clinic_id' => $clinic,
                'patient_id' => $patient,
                'channel' => $channel,
                'fee_policy' => $policy,
                'fee_toman' => $fee,
                'status' => $status,
            ],
            $id,
        );
        if ($status === 'confirmed') {
            notify_booking($id, 'نوبت پذیرش ثبت شد', jdate($start, true));
            booking_sms($id, 'booking_confirmed');
        }
        return $id;
    });
}
function reception_change(array $data): void
{
    $id = positive_id($data['id'] ?? 0);
    $old = reception_booking($id, 'bookings');
    db_transaction(function () use ($data, $id, $old) {
        lock_doctor((int) $old['doctor_id']);
        lock_contact($old['phone']);
        $b = reception_booking($id, 'bookings');
        if ((int) $b['revision'] !== (int) ($data['revision'] ?? 0)) {
            throw new AuthError('نوبت تغییر کرده است؛ صفحه را تازه کنید.', 409);
        }
        $op = field($data, 'operation', 20);
        $reason = field($data, 'reason', 500);
        if (in_array($op, ['cancelled', 'reschedule'], true) && $b['status'] === 'confirmed') {
            if ($op === 'reschedule' && $b['attendance_state'] !== 'waiting') {
                throw new AuthError('پس از پذیرش بیمار، جابه‌جایی مجاز نیست.', 409);
            }
            if ($op === 'reschedule') {
                $start = parse_jdate(field($data, 'starts_at', 30), true);
                contact_limit(
                    $b['phone'],
                    $b['patient_name'],
                    (int) $b['doctor_id'],
                    $start,
                    date('Y-m-d H:i:s', strtotime($start . ' +1 minute')),
                    $id,
                );
            }
            change_booking((int) $b['doctor_id'], $data);
            return;
        }
        if ($op === 'release' && $b['status'] === 'held') {
            query("UPDATE bookings SET status='cancelled',revision=revision+1 WHERE id=?", [$id]);
            waitlist_signal((int) $b['clinic_id']);
            event_record(
                (int) $b['doctor_id'],
                'reception.released',
                ['clinic_id' => $b['clinic_id'], 'reason' => $reason],
                $id,
            );
            return;
        }
        if ($b['status'] !== 'confirmed') {
            throw new AuthError('این وضعیت قابل تغییر نیست.', 409);
        }
        $state = $b['attendance_state'];
        $allowed = [
            'waiting' => ['arrived', 'no_show'],
            'arrived' => ['in_visit', 'no_show'],
            'in_visit' => ['completed'],
        ];
        if (!in_array($op, $allowed[$state] ?? [], true)) {
            throw new AuthError('ترتیب وضعیت پذیرش مجاز نیست.', 409);
        }
        if (substr($b['starts_at'], 0, 10) !== date('Y-m-d')) {
            throw new AuthError('ثبت حضور فقط در روز نوبت مجاز است.', 409);
        }
        if ($op === 'no_show' && $b['starts_at'] > date('Y-m-d H:i:s')) {
            throw new AuthError('زمان نوبت هنوز نرسیده است.', 409);
        }
        if (in_array($op, ['completed', 'no_show'], true)) {
            query('UPDATE bookings SET status=?,revision=revision+1 WHERE id=?', [$op, $id]);
        } else {
            query('UPDATE bookings SET attendance_state=?,revision=revision+1 WHERE id=?', [$op, $id]);
        }
        if ($op === 'arrived') {
            queue_assign($id, (int) $b['clinic_id'], date('Y-m-d'));
        } elseif ($op === 'in_visit') {
            queue_call($id, (int) $b['clinic_id'], date('Y-m-d'));
        }
        event_record(
            (int) $b['doctor_id'],
            'reception.attendance',
            ['clinic_id' => $b['clinic_id'], 'before' => $state, 'after' => $op, 'reason' => $reason],
            $id,
        );
        notify_booking(
            $id,
            'وضعیت پذیرش تغییر کرد',
            attendance_label(reception_booking($id)) . ' • ' . jdate($b['starts_at'], true),
        );
    });
}
function reception_list(int $clinic, string $day, string $view = 'day', string $status = ''): array
{
    $c = reception_scope($clinic);
    valid_date($day);
    if (!in_array($view, ['day', 'week'], true)) {
        throw new AuthError('نمای تقویم نامعتبر است.');
    }
    $to = $view === 'week' ? date('Y-m-d', strtotime($day . ' +6 days')) : $day;
    if ($day < date('Y-m-d')) {
        reception_scope($clinic, 'history');
    }
    $statuses = ['waiting', 'arrived', 'in_visit', 'completed', 'cancelled', 'no_show', 'held', 'expired'];
    if ($status !== '' && !in_array($status, $statuses, true)) {
        throw new AuthError('فیلتر وضعیت نامعتبر است.');
    }
    $rows = query(
        'SELECT b.*,s.starts_at,s.ends_at,s.service_name,s.clinic_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND s.starts_at>=? AND s.starts_at<? ORDER BY s.starts_at,b.id LIMIT 2001',
        [$clinic, $day . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'],
    )->fetchAll();
    if (count($rows) > 2000) {
        throw new AuthError('نتیجه بیش از ۲۰۰۰ نوبت است؛ روزانه مشاهده کنید.');
    }
    foreach ($rows as &$b) {
        if ($b['status'] === 'held' && $b['expires_at'] <= date('Y-m-d H:i:s')) {
            $b['status'] = 'expired';
        }
        $b['reception_status'] = $b['status'] === 'confirmed' ? $b['attendance_state'] : $b['status'];
        $b['status_label'] = attendance_label($b);
        $b['starts_at_jalali'] = jdate($b['starts_at'], true);
    }
    unset($b);
    return $status === ''
        ? $rows
        : array_values(array_filter($rows, fn($b) => $b['reception_status'] === $status));
}
function reception_claim(string $code): int
{
    $u = require_user();
    if (!preg_match('/^([0-9]{12}|[A-F0-9]{32})$/', $code)) {
        throw new AuthError('شناسه نامعتبر است.', 404);
    }
    // Links sent before 1.0.0-rc.7 carry the old 32-character code, kept in legacy_tracking_code.
    $old = query(
        "SELECT b.id,s.doctor_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE (b.tracking_code=? OR b.legacy_tracking_code=?) AND b.booking_channel IN ('phone','in_person') AND b.phone=?",
        [$code, $code, $u['mobile']],
    )->fetch();
    if (!$old) {
        throw new AuthError('رزرو متعلق به موبایل تأییدشده شما نیست.', 403);
    }
    return db_transaction(function () use ($u, $old) {
        lock_doctor((int) $old['doctor_id']);
        lock_contact($u['mobile']);
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$u['id']]);
        $b = booking_row((int) $old['id']);
        if ($b['patient_user_id'] && (int) $b['patient_user_id'] !== (int) $u['id']) {
            throw new AuthError('رزرو به حساب دیگری متصل است.', 403);
        }
        if (!$b['patient_user_id']) {
            $member = null;
            $intake = query('SELECT parent_id FROM reception_patients WHERE id=?', [
                $b['intake_patient_id'],
            ])->fetch();
            if (!empty($intake['parent_id'])) {
                $member = query('SELECT id FROM family_members WHERE user_id=? AND name=? AND active=1', [
                    $u['id'],
                    $b['patient_name'],
                ])->fetchColumn();
                if (!$member) {
                    if (
                        (int) query('SELECT COUNT(*) FROM family_members WHERE user_id=? AND active=1', [
                            $u['id'],
                        ])->fetchColumn() >= 20
                    ) {
                        throw new AuthError('سقف اعضای خانواده تکمیل است.');
                    }
                    query("INSERT INTO family_members(user_id,name,relationship) VALUES(?,?,'سایر')", [
                        $u['id'],
                        $b['patient_name'],
                    ]);
                    $member = (int) db()->lastInsertId();
                }
            }
            query('UPDATE bookings SET patient_user_id=?,family_member_id=? WHERE id=?', [
                $u['id'],
                $member ?: null,
                $b['id'],
            ]);
            event_record(
                (int) $b['doctor_id'],
                'reception.claimed',
                ['clinic_id' => $b['clinic_id']],
                (int) $b['id'],
            );
        }
        return (int) $b['id'];
    });
}
function reception_delay(array $data): int
{
    $clinic = positive_id($data['clinic_id'] ?? 0);
    $c = reception_scope($clinic, 'bookings');
    $start = parse_jdate(field($data, 'starts_at', 30), true);
    $end = parse_jdate(field($data, 'ends_at', 30), true);
    $minutes = number_range($data, 'minutes', 1, 360);
    $reason = field($data, 'reason', 500);
    if ($end <= $start || strtotime($end) - strtotime($start) > 86400 || $end <= date('Y-m-d H:i:s')) {
        throw new AuthError('بازه تأخیر باید معتبر، حداکثر یک روز و پایان آن در آینده باشد.');
    }
    $smsAll = !empty($data['send_sms']);
    return db_transaction(function () use ($clinic, $c, $start, $end, $minutes, $reason, $smsAll) {
        lock_doctor((int) $c['doctor_id']);
        reception_scope($clinic, 'bookings');
        query(
            'INSERT INTO reception_delays(clinic_id,starts_at,ends_at,minutes,reason,created_by) VALUES(?,?,?,?,?,?)',
            [$clinic, $start, $end, $minutes, $reason, require_user()['id']],
        );
        $id = (int) db()->lastInsertId();
        $rows = query(
            "SELECT b.id,b.phone,b.patient_user_id,b.estimated_at,s.starts_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND s.starts_at>=? AND s.starts_at<? AND b.status='confirmed' ORDER BY b.id LIMIT 2001",
            [$clinic, $start, $end],
        )->fetchAll();
        if (count($rows) > 2000) {
            throw new AuthError('بازه کوچک‌تر انتخاب کنید.');
        }
        foreach ($rows as $b) {
            query('INSERT INTO reception_delay_recipients(delay_id,booking_id,mobile) VALUES(?,?,?)', [
                $id,
                $b['id'],
                $b['phone'],
            ]);
            // The approximate time moves later; the booked time itself never changes silently.
            $eta = date('Y-m-d H:i:s', strtotime($b['starts_at'] . ' +' . $minutes . ' minutes'));
            if (!$b['estimated_at'] || $b['estimated_at'] < $eta) {
                query('UPDATE bookings SET estimated_at=? WHERE id=?', [$eta, $b['id']]);
            }
            notify_booking(
                (int) $b['id'],
                'تأخیر پزشک',
                fa_number($minutes) .
                    ' دقیقه تأخیر اعلام شده است؛ زمان تقریبی مراجعه ' .
                    fa_number(substr($eta, 11, 5)) .
                    ' است و زمان اصلی نوبت تغییر نکرده است. ' .
                    $reason,
                'delay',
            );
            if ($smsAll && sms_allowed_for($b['patient_user_id'] ? (int) $b['patient_user_id'] : null, 'delay')) {
                sms_enqueue('delay', $b['phone'], ['token' => (string) $minutes, 'token2' => digits(jdate($b['starts_at'])), 'token3' => substr($b['starts_at'], 11, 5)], 'delay:' . $id . ':' . $b['id'], [
                    'booking_id' => (int) $b['id'],
                ]);
            }
            event_record(
                (int) $c['doctor_id'],
                'reception.delay',
                ['clinic_id' => $clinic, 'delay_id' => $id, 'minutes' => $minutes, 'reason' => $reason],
                (int) $b['id'],
            );
        }
        event_record((int) $c['doctor_id'], 'reception.delay_created', [
            'clinic_id' => $clinic,
            'delay_id' => $id,
            'recipients' => count($rows),
            'minutes' => $minutes,
        ]);
        return $id;
    });
}
function delay_recipient(int $id): array
{
    $r = query(
        'SELECT r.*,d.clinic_id,d.minutes,d.starts_at,d.ends_at,c.doctor_id,s.starts_at appointment_at FROM reception_delay_recipients r JOIN reception_delays d ON d.id=r.delay_id JOIN clinics c ON c.id=d.clinic_id JOIN bookings b ON b.id=r.booking_id JOIN slots s ON s.id=b.slot_id WHERE r.id=?',
        [$id],
    )->fetch();
    if (!$r) {
        throw new AuthError('پیام پیدا نشد.', 404);
    }
    reception_scope((int) $r['clinic_id'], 'bookings');
    reception_booking((int) $r['booking_id'], 'bookings');
    return $r;
}
function reception_contact(array $data): void
{
    $id = positive_id($data['id'] ?? 0);
    $r = delay_recipient($id);
    $note = field($data, 'note', 500);
    if (empty($data['contacted'])) {
        throw new AuthError('اطلاع‌رسانی واقعی را تأیید کنید.');
    }
    db_transaction(function () use ($id, $r, $note) {
        lock_doctor((int) $r['doctor_id']);
        delay_recipient($id);
        query(
            'UPDATE reception_delay_recipients SET contacted_at=NOW(),contacted_by=?,contact_note=? WHERE id=?',
            [require_user()['id'], $note, $id],
        );
        event_record(
            (int) $r['doctor_id'],
            'reception.contact',
            ['clinic_id' => $r['clinic_id'], 'recipient_id' => $id, 'note' => $note],
            (int) $r['booking_id'],
        );
    });
}
function reception_sms(int $id, ?callable $transport = null): void
{
    if ($transport && PHP_SAPI !== 'cli') {
        throw new AuthError('انتقال آزمون فقط CLI است.', 403);
    }
    $r = delay_recipient($id);
    $current = reception_booking((int) $r['booking_id'], 'bookings');
    if (
        $current['status'] !== 'confirmed' ||
        $current['starts_at'] < $r['starts_at'] ||
        $current['starts_at'] >= $r['ends_at']
    ) {
        throw new AuthError('نوبت لغو یا از بازه تأخیر جابه‌جا شده است؛ ارسال پیامک مجاز نیست.', 409);
    }
    $tpl = sms_template('delay');
    if (!sms_dev_mode() && (!sms_enabled() || !sms_template_ready($tpl))) {
        throw new AuthError('الگوی پیامک تأخیر توسط مدیر تنظیم نشده است.', 503);
    }
    db_transaction(function () use ($id, $r) {
        lock_doctor((int) $r['doctor_id']);
        delay_recipient($id);
        $q = query(
            "UPDATE reception_delay_recipients SET sms_state='sending' WHERE id=? AND sms_state='pending'",
            [$id],
        );
        if (!$q->rowCount()) {
            throw new AuthError('این ارسال قبلاً آغاز شده؛ ارسال مجدد خودکار مجاز نیست.', 409);
        }
        event_record(
            (int) $r['doctor_id'],
            'reception.sms_started',
            ['clinic_id' => $r['clinic_id'], 'recipient_id' => $id],
            (int) $r['booking_id'],
        );
    });
    try {
        $result = sms_send_now(
            'delay',
            $r['mobile'],
            [
                'token' => (string) $r['minutes'],
                'token2' => digits(jdate($r['appointment_at'])),
                'token3' => substr($r['appointment_at'], 11, 5),
            ],
            $transport,
            ['booking_id' => (int) $r['booking_id']],
        );
        if (empty($result['messageid'])) {
            throw new AuthError('شناسه پیامک دریافت نشد.', 503);
        }
        db_transaction(function () use ($r, $id, $result) {
            query("UPDATE reception_delay_recipients SET sms_state='sent',message_id=? WHERE id=?", [
                (string) $result['messageid'],
                $id,
            ]);
            event_record(
                (int) $r['doctor_id'],
                'reception.sms_accepted',
                [
                    'clinic_id' => $r['clinic_id'],
                    'recipient_id' => $id,
                    'message_id' => (string) $result['messageid'],
                ],
                (int) $r['booking_id'],
            );
        });
    } catch (Throwable $e) {
        db_transaction(function () use ($id, $r) {
            query(
                "UPDATE reception_delay_recipients SET sms_state='unknown' WHERE id=? AND sms_state='sending'",
                [$id],
            );
            event_record(
                (int) $r['doctor_id'],
                'reception.sms_unknown',
                ['clinic_id' => $r['clinic_id'], 'recipient_id' => $id],
                (int) $r['booking_id'],
            );
        });
        throw new AuthError(
            'نتیجه پیامک نامشخص یا ناموفق است؛ پنل کاوه‌نگار را بررسی و با بیمار تماس بگیرید.',
            503,
        );
    }
}
