<?php
declare(strict_types=1);
function doctor_action(int $doctor, string $action, array $data): array
{
    $clinic = (int) ($data['clinic_id'] ?? 0);
    if ($action === 'block_days') {
        // Simple form: whole days (Jalali dates); times only when «فقط چند ساعت» is chosen.
        $data = block_days_input($data);
        $action = 'block';
    }
    $permission = in_array(
        $action,
        ['hours', 'hours_remove', 'block', 'block_remove', 'clinic_settings', 'week'],
        true,
    )
        ? 'schedule'
        : ($action === 'booking'
            ? 'bookings'
            : 'owner');
    if ($action === 'booking') {
        $b = query(
            'SELECT s.clinic_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.id=? AND s.doctor_id=?',
            [(int) ($data['id'] ?? 0), $doctor],
        )->fetch();
        if (!$b) {
            throw new AuthError('نوبت پیدا نشد.', 404);
        }
        $clinic = (int) $b['clinic_id'];
    }
    if (
        in_array(
            $action,
            ['hours', 'hours_remove', 'block', 'block_remove', 'clinic_settings', 'week', 'staff', 'staff_remove'],
            true,
        ) &&
        $clinic < 1
    ) {
        throw new AuthError('مطب را انتخاب کنید.');
    }
    $actor = doctor_actor($doctor, $permission, $clinic, true);
    if ($action === 'booking' && !$actor['is_owner']) {
        reception_change($data);
        return ['message' => 'تغییر پذیرش ثبت شد.'];
    }
    return db_transaction(function () use ($doctor, $action, $data, $clinic, $actor) {
        lock_doctor($doctor);
        doctor_actor(
            $doctor,
            in_array($action, ['hours', 'hours_remove', 'block', 'block_remove', 'clinic_settings'], true)
                ? 'schedule'
                : ($action === 'booking'
                    ? 'bookings'
                    : 'owner'),
            $clinic,
            true,
        );
        $id = (int) ($data['id'] ?? 0);
        $message = 'تغییرات ذخیره شد. نوبت‌های قبلی حفظ شدند.';
        $details = [];
        switch ($action) {
            case 'photo_remove':
                query('UPDATE doctors SET photo_file=NULL WHERE id=?', [$doctor]);
                $message = 'تصویر از پروفایل حذف شد.';
                break;
            case 'profile':
                $name = field($data, 'name', 190);
                validate_doctor_name($name);
                if (mb_strlen($name) < 3) {
                    throw new AuthError('نام کامل را وارد کنید.');
                }
                $sp = active_specialty(positive_id($data['specialty_id'] ?? 0));
                $title = field($data, 'professional_title', 190, false);
                $education = field($data, 'education', 1000, false);
                $bio = field($data, 'bio', 5000, false);
                $years = number_range($data, 'experience_years', 0, 70);
                $old = query('SELECT * FROM doctors WHERE id=?', [$doctor])->fetch();
                $review = $old['name'] !== $name || (int) $old['specialty_id'] !== (int) $sp['id'];
                query(
                    'UPDATE doctors SET name=?,specialty_id=?,specialty=?,professional_title=?,education=?,bio=?,experience_years=?,review_status=?,active=? WHERE id=?',
                    [
                        $name,
                        $sp['id'],
                        $sp['name'],
                        $title,
                        $education,
                        $bio,
                        $years,
                        $review ? 'pending' : $old['review_status'],
                        $review ? 0 : $old['active'],
                        $doctor,
                    ],
                );
                doctor_set_extra($doctor, $data);
                $details = [
                    'changed' => [
                        'name',
                        'specialty',
                        'professional_title',
                        'education',
                        'bio',
                        'experience_years',
                    ],
                    'review_required' => $review,
                ];
                if ($review) {
                    $message =
                        'اطلاعات ذخیره شد. تغییر نام یا تخصص برای تأیید مدیر ارسال شد؛ رزرو جدید تا تأیید متوقف است و نوبت‌های قبلی حفظ شدند.';
                }
                break;
            case 'clinic':
                [$name, $city, $address, $phone, $lat, $lng] = clinic_data($data);
                if (!$id) {
                    // rc.20: clinic limit of the free plan / Pro plan (lib/pro.php).
                    require_within_limit($doctor, 'clinics', (int) query('SELECT COUNT(*) FROM clinics WHERE doctor_id=? AND active=1 AND center_id IS NULL', [$doctor])->fetchColumn());
                }
                $active = !empty($data['active']) ? 1 : 0;
                $old = $id
                    ? query('SELECT * FROM clinics WHERE id=? AND doctor_id=?', [$id, $doctor])->fetch()
                    : null;
                if ($id && !$old) {
                    throw new AuthError('مطب پیدا نشد.', 404);
                }
                if ($old && $old['center_id']) {
                    throw new AuthError('مشخصات حضور در شعبه مرکز درمانی توسط مرکز تعیین می‌شود؛ از بخش «مراکز درمانی» مدیریت کنید.', 403);
                }
                if (
                    $old &&
                    ((int) $old['city_id'] !== (int) $city['id'] || $old['address'] !== $address) &&
                    query(
                        'SELECT b.id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? LIMIT 1',
                        [$id],
                    )->fetch()
                ) {
                    throw new AuthError('برای تغییر نشانی مطب دارای سابقه رزرو، مطب جدید ثبت کنید.');
                }
                $primary =
                    !empty($data['is_primary']) ||
                    ($old && $old['is_primary']) ||
                    !query('SELECT id FROM clinics WHERE doctor_id=? AND is_primary=1 AND center_id IS NULL', [$doctor])->fetch();
                if ($primary && !$active) {
                    throw new AuthError('ابتدا مطب دیگری را اصلی کنید.');
                }
                if ($primary) {
                    query('UPDATE clinics SET is_primary=0 WHERE doctor_id=? AND center_id IS NULL', [$doctor]);
                }
                if ($id) {
                    query(
                        'UPDATE clinics SET name=?,city_id=?,address=?,phone=?,active=?,is_primary=?,latitude=?,longitude=? WHERE id=?',
                        [$name, $city['id'], $address, $phone, $active, (int) $primary, $lat, $lng, $id],
                    );
                } else {
                    query(
                        'INSERT INTO clinics(doctor_id,name,city_id,address,phone,active,is_primary,latitude,longitude) VALUES(?,?,?,?,?,?,?,?,?)',
                        [$doctor, $name, $city['id'], $address, $phone, $active, (int) $primary, $lat, $lng],
                    );
                    $id = (int) db()->lastInsertId();
                }
                clinic_set_district($id, $data);
                sync_primary($doctor);
                entitlement_sync($doctor);
                $details = ['clinic_id' => $id, 'active' => $active, 'name' => $name];
                break;
            case 'clinic_settings':
                $duration = number_range($data, 'default_duration', 5, 180);
                $gap = number_range($data, 'gap_minutes', 0, 120);
                $capacity = number_range($data, 'daily_capacity', 1, 500);
                $before = query(
                    'SELECT default_duration,gap_minutes,daily_capacity,slot_capacity FROM clinics WHERE id=?',
                    [$clinic],
                )->fetch();
                $seats = number_range(
                    $data + ['slot_capacity' => $before['slot_capacity']],
                    'slot_capacity',
                    1,
                    20,
                );
                $details = [
                    'clinic_id' => $clinic,
                    'before' => $before,
                    'after' => [
                        'default_duration' => $duration,
                        'gap_minutes' => $gap,
                        'daily_capacity' => $capacity,
                        'slot_capacity' => $seats,
                    ],
                ];
                query(
                    'UPDATE clinics SET default_duration=?,gap_minutes=?,daily_capacity=?,slot_capacity=? WHERE id=?',
                    [$duration, $gap, $capacity, $seats, $clinic],
                );
                break;
            case 'service':
                $name = field($data, 'name', 190);
                validate_doctor_name($name);
                $duration = number_range($data, 'duration_minutes', 0, 180);
                if ($duration > 0 && $duration < 5) {
                    throw new AuthError('مدت خدمت حداقل ۵ دقیقه است؛ صفر یعنی پیش‌فرض مطب.');
                }
                $active = !empty($data['active']) ? 1 : 0;
                // Visit fee is information only (paid at the clinic); never part of the online payment.
                $visit = array_key_exists('visit_fee', $data) ? trim((string) $data['visit_fee']) : null;
                $visitFee = $visit === null || $visit === '' ? null : parse_toman(field($data, 'visit_fee', 20));
                if ($visitFee !== null && ($visitFee < 1000 || $visitFee > 100000000)) {
                    throw new AuthError('تعرفه ویزیت را بین ۱٬۰۰۰ و ۱۰۰٬۰۰۰٬۰۰۰ تومان وارد کنید یا خالی بگذارید.');
                }
                if ($id) {
                    if (
                        !query('SELECT id FROM doctor_services WHERE id=? AND doctor_id=?', [
                            $id,
                            $doctor,
                        ])->fetch()
                    ) {
                        throw new AuthError('خدمت پیدا نشد.', 404);
                    }
                    query('UPDATE doctor_services SET name=?,duration_minutes=?,active=? WHERE id=?', [
                        $name,
                        $duration,
                        $active,
                        $id,
                    ]);
                } else {
                    // rc.20: service limit of the free plan / Pro plan.
                    require_within_limit($doctor, 'services', (int) query('SELECT COUNT(*) FROM doctor_services WHERE doctor_id=? AND active=1', [$doctor])->fetchColumn());
                    query(
                        'INSERT INTO doctor_services(doctor_id,name,duration_minutes,active) VALUES(?,?,?,?)',
                        [$doctor, $name, $duration, $active],
                    );
                    $id = (int) db()->lastInsertId();
                }
                if ($visit !== null) {
                    query('UPDATE doctor_services SET visit_fee_toman=? WHERE id=?', [$visitFee, $id]);
                }
                $details = [
                    'service_id' => $id,
                    'name' => $name,
                    'duration' => $duration,
                    'active' => $active,
                ];
                entitlement_sync($doctor);
                break;
            case 'hours':
                $details = working_hours_add($doctor, $clinic, $data);
                break;
            case 'services_pick':
                $details = services_pick($doctor, $data);
                $message = 'خدمات قابل رزرو ذخیره شد.';
                break;
            case 'week':
                $week = weekly_hours_input($data);
                query('UPDATE working_hours SET active=0 WHERE clinic_id=? AND active=1', [$clinic]);
                foreach ($week as $row) {
                    working_hours_add($doctor, $clinic, $row);
                }
                $details = ['clinic_id' => $clinic, 'week' => $week];
                $message = $week ? 'برنامه هفتگی ذخیره شد.' : 'همه روزها تعطیل ثبت شد؛ تا تعریف ساعت حضور، نوبتی ارائه نمی‌شود.';
                // Without an active service nobody can book: add «ویزیت عمومی» so the new hours are usable at once.
                if ($week && default_service_ensure($doctor)) {
                    $message .= ' خدمت «ویزیت عمومی» هم خودکار اضافه شد تا بیماران بتوانند نوبت بگیرند.';
                }
                break;
            case 'hours_remove':
                $old = query('SELECT * FROM working_hours WHERE id=? AND clinic_id=? AND active=1', [
                    $id,
                    $clinic,
                ])->fetch();
                if (!$old) {
                    throw new AuthError('ساعت کاری پیدا نشد.', 404);
                }
                query('UPDATE working_hours SET active=0 WHERE id=?', [$id]);
                $details = $old;
                break;
            case 'block':
                $start = parse_jdate(field($data, 'starts_at', 30), true);
                $end = parse_jdate(field($data, 'ends_at', 30), true);
                if ($end <= $start) {
                    throw new AuthError('پایان تعطیلی باید پس از شروع باشد.');
                }
                $reason = field($data, 'reason', 500);
                $kind = field($data, 'kind', 20);
                if (!in_array($kind, ['holiday', 'leave', 'closed'], true)) {
                    throw new AuthError('نوع تعطیلی نامعتبر است.');
                }
                $all = !empty($data['all_clinics']);
                if ($all && !$actor['is_owner']) {
                    throw new AuthError('تعطیلی همه مطب‌ها فقط با دسترسی پزشک مجاز است.', 403);
                }
                query(
                    'INSERT INTO schedule_blocks(doctor_id,clinic_id,starts_at,ends_at,reason,kind) VALUES(?,?,?,?,?,?)',
                    [$doctor, $all ? null : $clinic, $start, $end, $reason, $kind],
                );
                $details = [
                    'clinic_id' => $all ? null : $clinic,
                    'start' => $start,
                    'end' => $end,
                    'reason' => $reason,
                    'kind' => $kind,
                ];
                $affected = (int) query(
                    "SELECT COUNT(*) FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.doctor_id=? AND (?=1 OR s.clinic_id=?) AND b.status='confirmed' AND s.starts_at<? AND s.ends_at>?",
                    [$doctor, (int) $all, $clinic, $end, $start],
                )->fetchColumn();
                $details['preserved_bookings'] = $affected;
                $message =
                    'بازه بسته شد. ' .
                    fa_number($affected) .
                    ' نوبت قطعی در این بازه حفظ شد؛ برای تغییر هر نوبت، بیمار را مطلع و از بخش نوبت‌ها اقدام کنید.';
                break;
            case 'block_remove':
                $old = query('SELECT * FROM schedule_blocks WHERE id=? AND doctor_id=? AND active=1', [
                    $id,
                    $doctor,
                ])->fetch();
                if (!$old || (!$actor['is_owner'] && (int) $old['clinic_id'] !== $clinic)) {
                    throw new AuthError('تعطیلی در محدوده مجاز پیدا نشد.', 403);
                }
                query('UPDATE schedule_blocks SET active=0 WHERE id=?', [$id]);
                $details = $old;
                break;
            case 'staff':
                $mobile = digits(field($data, 'mobile', 20));
                $u = query('SELECT id FROM users WHERE mobile=? AND active=1 AND verified_at IS NOT NULL', [
                    $mobile,
                ])->fetch();
                if (!$u) {
                    throw new AuthError('منشی باید ابتدا با موبایل خود وارد سامانه شود.');
                }
                if ((int) $u['id'] === (int) require_user()['id']) {
                    throw new AuthError('حساب خودتان را منشی تعریف نکنید.');
                }
                if (!$clinic) {
                    throw new AuthError('مطب را انتخاب کنید.');
                }
                $existing = query(
                    "SELECT DISTINCT s.user_id FROM staff_scopes s JOIN clinics c ON c.id=s.clinic_id WHERE c.doctor_id=? AND s.role='secretary' AND s.center_id IS NULL",
                    [$doctor],
                )->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array($u['id'], array_map('intval', $existing), true)) {
                    // rc.20: secretaries are a Pro feature by default (free limit 0).
                    require_within_limit($doctor, 'secretaries', count($existing));
                }
                $schedule = (int) !empty($data['can_manage_schedule']);
                $bookings = (int) !empty($data['can_manage_bookings']);
                $history = (int) !empty($data['can_view_history']);
                query("INSERT IGNORE INTO user_roles(user_id,role) VALUES(?,'secretary')", [$u['id']]);
                query(
                    "INSERT INTO staff_scopes(user_id,clinic_id,role,can_manage_schedule,can_manage_bookings,can_view_history) VALUES(?,?,'secretary',?,?,?) ON DUPLICATE KEY UPDATE can_manage_schedule=VALUES(can_manage_schedule),can_manage_bookings=VALUES(can_manage_bookings),can_view_history=VALUES(can_view_history)",
                    [$u['id'], $clinic, $schedule, $bookings, $history],
                );
                $details = [
                    'user_id' => $u['id'],
                    'clinic_id' => $clinic,
                    'permissions' => [$schedule, $bookings, $history],
                ];
                break;
            case 'staff_remove':
                query("DELETE FROM staff_scopes WHERE user_id=? AND clinic_id=? AND role='secretary' AND center_id IS NULL", [
                    $id,
                    $clinic,
                ]);
                if (
                    !query("SELECT user_id FROM staff_scopes WHERE user_id=? AND role='secretary'", [
                        $id,
                    ])->fetch()
                ) {
                    query("DELETE FROM user_roles WHERE user_id=? AND role='secretary'", [$id]);
                }
                $details = ['user_id' => $id, 'clinic_id' => $clinic];
                break;
            case 'booking':
                return change_booking($doctor, $data);
            default:
                throw new AuthError('عملیات نامعتبر است.');
        }
        event_record($doctor, 'doctor.' . $action, $details);
        return ['message' => $message];
    });
}
/**
 * Weekly editor: day[W][on]=1 and day[W][from][]/[to][] (up to three shifts per day, 24-hour HH:MM),
 * day[W][room] for center rooms, day[W][len] for that day's appointment length (empty = clinic default),
 * valid_from/valid_until (Jalali, optional) for the dates the program holds. Returns rows for working_hours_add(); errors name the day.
 */
function weekly_hours_input(array $data): array
{
    $names = [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
    $days = is_array($data['day'] ?? null) ? $data['day'] : [];
    $validFrom = field($data, 'valid_from', 10, false) === '' ? null : parse_jdate(field($data, 'valid_from', 10));
    $validUntil = field($data, 'valid_until', 10, false) === '' ? null : parse_jdate(field($data, 'valid_until', 10));
    if ($validFrom && $validUntil && $validUntil < $validFrom) {
        throw new AuthError('«تا تاریخ» برنامه نباید قبل از «از تاریخ» باشد.');
    }
    if ($validUntil && $validUntil < date('Y-m-d')) {
        throw new AuthError('تاریخ پایان برنامه گذشته است؛ تاریخ تازه‌تری وارد کنید یا خالی بگذارید.');
    }
    $rows = [];
    foreach ($names as $w => $label) {
        $d = is_array($days[$w] ?? null) ? $days[$w] : [];
        if (empty($d['on'])) {
            continue;
        }
        $from = array_values(array_filter((array) ($d['from'] ?? []), 'is_scalar'));
        $to = array_values(array_filter((array) ($d['to'] ?? []), 'is_scalar'));
        $shifts = [];
        for ($i = 0; $i < min(3, max(count($from), count($to))); $i++) {
            $a = digits(trim((string) ($from[$i] ?? '')));
            $b = digits(trim((string) ($to[$i] ?? '')));
            if ($a === '' && $b === '') {
                continue;
            }
            if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $a) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $b)) {
                throw new AuthError($label . ': ساعت را به شکل ۰۹:۳۰ وارد کنید.');
            }
            if ($a >= $b) {
                throw new AuthError($label . ': ساعت پایان باید بعد از ساعت شروع باشد.');
            }
            foreach ($shifts as [$x, $y]) {
                if ($a < $y && $b > $x) {
                    throw new AuthError($label . ': دو بازه این روز با هم هم‌پوشانی دارند.');
                }
            }
            $shifts[] = [$a, $b];
        }
        if (!$shifts) {
            throw new AuthError($label . ' انتخاب شده ولی ساعت حضور ندارد؛ ساعت را وارد کنید یا تیک روز را بردارید.');
        }
        $room = empty($d['room']) ? '' : (string) (int) $d['room'];
        $len = is_scalar($d['len'] ?? '') ? digits(trim((string) ($d['len'] ?? ''))) : 'x';
        if ($len !== '' && (!ctype_digit($len) || (int) $len < 5 || (int) $len > 180)) {
            throw new AuthError($label . ': مدت نوبت باید بین ۵ تا ۱۸۰ دقیقه باشد.');
        }
        foreach ($shifts as [$a, $b]) {
            $rows[] = ['weekday' => $w, 'opens_at' => $a, 'closes_at' => $b, 'room_id' => $room, 'slot_minutes' => $len, 'valid_from' => $validFrom, 'valid_until' => $validUntil];
        }
    }
    return $rows;
}

/** Common services offered as one-tap choices on the schedule page and as name suggestions. */
function service_presets(): array
{
    return ['ویزیت عمومی', 'ویزیت تخصصی', 'ویزیت مجدد (پیگیری)', 'مشاوره', 'معاینه و تجویز', 'نوار قلب', 'سونوگرافی', 'تزریق و پانسمان'];
}

/** Adds (or turns back on) «ویزیت عمومی» when the doctor has no active service; true when it did. */
function default_service_ensure(int $doctor): bool
{
    if (query('SELECT id FROM doctor_services WHERE doctor_id=? AND active=1 LIMIT 1', [$doctor])->fetch()) {
        return false;
    }
    $old = query("SELECT id FROM doctor_services WHERE doctor_id=? AND name='ویزیت عمومی' ORDER BY id LIMIT 1", [$doctor])->fetchColumn();
    if ($old) {
        query('UPDATE doctor_services SET active=1 WHERE id=?', [$old]);
    } else {
        query("INSERT INTO doctor_services(doctor_id,name,duration_minutes,active) VALUES(?,'ویزیت عمومی',0,1)", [$doctor]);
    }
    entitlement_sync($doctor);
    return true;
}

/**
 * Schedule page quick pick: svc[] = names to offer (ticked chips), svc_other = one more typed name.
 * Ticked existing services are turned on, the rest off; new names are added with the clinic's length.
 */
function services_pick(int $doctor, array $data): array
{
    $picked = [];
    foreach (array_merge((array) ($data['svc'] ?? []), [$data['svc_other'] ?? '']) as $n) {
        $n = is_scalar($n) ? trim(strtr((string) $n, ['ي' => 'ی', 'ك' => 'ک'])) : '';
        if ($n !== '') {
            if (mb_strlen($n) > 190) {
                throw new AuthError('نام خدمت حداکثر ۱۹۰ نویسه است.');
            }
            validate_doctor_name($n);
            $picked[$n] = true;
        }
    }
    if (!$picked) {
        throw new AuthError('دست‌کم یک خدمت انتخاب کنید؛ بدون خدمت، بیماران نمی‌توانند نوبت بگیرند.');
    }
    require_within_limit($doctor, 'services', count($picked) - 1);
    $have = [];
    foreach (query('SELECT id,name FROM doctor_services WHERE doctor_id=?', [$doctor])->fetchAll() as $s) {
        query('UPDATE doctor_services SET active=? WHERE id=?', [isset($picked[$s['name']]) ? 1 : 0, $s['id']]);
        $have[$s['name']] = true;
    }
    foreach (array_keys($picked) as $n) {
        if (!isset($have[$n])) {
            query('INSERT INTO doctor_services(doctor_id,name,duration_minutes,active) VALUES(?,?,0,1)', [$doctor, $n]);
        }
    }
    entitlement_sync($doctor);
    return ['services' => array_keys($picked)];
}

/** «از تاریخ / تا تاریخ» (and optional hours) → the starts_at/ends_at pair of the closure action. */
function block_days_input(array $data): array
{
    $from = parse_jdate(field($data, 'from_date', 10));
    $to = trim((string) ($data['to_date'] ?? '')) === '' ? $from : parse_jdate(field($data, 'to_date', 10));
    if ($to < $from) {
        throw new AuthError('«تا تاریخ» نباید قبل از «از تاریخ» باشد.');
    }
    if (!empty($data['partial'])) {
        $a = digits(field($data, 'from_time', 5));
        $b = digits(field($data, 'to_time', 5));
        if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $a) || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $b) || $a >= $b) {
            throw new AuthError('ساعت شروع و پایان را درست وارد کنید (مثلاً ۱۰:۰۰ تا ۱۲:۰۰).');
        }
        $start = $from . ' ' . $a . ':00';
        $end = $to . ' ' . $b . ':00';
    } else {
        $start = $from . ' 00:00:00';
        $end = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';
    }
    $kind = in_array($data['kind'] ?? '', ['holiday', 'leave', 'closed'], true) ? $data['kind'] : 'leave';
    $reason = trim((string) ($data['reason'] ?? ''));
    return [
        'clinic_id' => $data['clinic_id'] ?? 0,
        'starts_at' => jdate($start, true),
        'ends_at' => jdate($end, true),
        'kind' => $kind,
        'reason' => $reason !== '' ? $reason : ['holiday' => 'تعطیلی', 'leave' => 'مرخصی پزشک', 'closed' => 'مطب بسته است'][$kind],
        'all_clinics' => $data['all_clinics'] ?? '',
    ];
}

function change_booking(int $doctor, array $data): array
{
    $id = positive_id($data['id'] ?? 0);
    $b = query(
        'SELECT b.*,s.doctor_id,s.clinic_id,s.starts_at,s.ends_at,s.service_id FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE b.id=? AND s.doctor_id=? FOR UPDATE',
        [$id, $doctor],
    )->fetch();
    if (!$b) {
        throw new AuthError('نوبت پیدا نشد.', 404);
    }
    if ($b['status'] !== 'confirmed' || (int) $b['revision'] !== (int) ($data['revision'] ?? 0)) {
        throw new AuthError('این نوبت تغییر کرده است؛ صفحه را تازه کنید.', 409);
    }
    $op = field($data, 'operation', 20);
    $reason = field($data, 'reason', 500);
    $before = [
        'slot_id' => $b['slot_id'],
        'clinic_id' => $b['clinic_id'],
        'starts_at' => $b['starts_at'],
        'status' => $b['status'],
    ];
    $status = $op;
    $after = [];
    if (in_array($op, ['cancelled', 'reschedule'], true) && empty($data['patient_contacted'])) {
        throw new AuthError('ابتدا بیمار را از تغییر مطلع کنید و تأیید اطلاع‌رسانی را علامت بزنید.');
    }
    if ($op === 'reschedule') {
        $clinic = positive_id($data['target_clinic_id'] ?? 0);
        doctor_actor($doctor, 'bookings', $clinic, true);
        $service = positive_id($data['service_id'] ?? 0);
        $start = parse_jdate(field($data, 'starts_at', 30), true);
        $match = null;
        foreach (availability($clinic, $service, substr($start, 0, 10), $id) as $a) {
            if ($a['starts_at'] === $start) {
                $match = $a;
            }
        }
        if (!$match) {
            throw new AuthError('زمان جدید با برنامه کاری، ظرفیت یا نوبت‌ها سازگار نیست.', 409);
        }
        lock_contact($b['phone']);
        contact_limit($b['phone'], $b['patient_name'], $doctor, $start, $match['ends_at'], $id);
        if ($b['patient_user_id']) {
            query('SELECT id FROM users WHERE id=? FOR UPDATE', [$b['patient_user_id']]);
            patient_limit(
                (int) $b['patient_user_id'],
                (int) $b['family_member_id'],
                $doctor,
                $start,
                $match['ends_at'],
                $id,
            );
        }
        if ($b['starts_at'] <= date('Y-m-d H:i:s')) {
            throw new AuthError('نوبت گذشته قابل جابه‌جایی نیست.');
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
        $slot = (int) db()->lastInsertId();
        query('UPDATE bookings SET slot_id=?,revision=revision+1 WHERE id=?', [$slot, $id]);
        $title = 'زمان نوبت تغییر کرد';
        $body =
            'زمان قبلی: ' .
            jdate($b['starts_at'], true) .
            '؛ زمان جدید: ' .
            jdate($start, true) .
            '؛ علت: ' .
            $reason;
        $after = ['slot_id' => $slot, 'clinic_id' => $clinic, 'starts_at' => $start];
    } else {
        if (!in_array($status, ['cancelled', 'completed', 'no_show'], true)) {
            throw new AuthError('وضعیت نامعتبر است.');
        }
        if (in_array($status, ['completed', 'no_show'], true) && $b['starts_at'] > date('Y-m-d H:i:s')) {
            throw new AuthError('تا زمان شروع نوبت امکان ثبت مراجعه یا عدم مراجعه نیست.');
        }
        query('UPDATE bookings SET status=?,revision=revision+1 WHERE id=?', [$status, $id]);
        $title = 'وضعیت نوبت: ' . booking_status_label($status);
        $body = jdate($b['starts_at'], true) . '؛ علت: ' . $reason;
        $after = ['status' => $status];
    }
    if ($op === 'cancelled') {
        booking_refund_on_cancel($id, $reason, true);
    }
    if (in_array($op, ['cancelled', 'reschedule'], true)) {
        // A moved booking also resets its approximate time and queue number; its old time is free again.
        query('UPDATE bookings SET estimated_at=NULL,queue_number=IF(?=1,NULL,queue_number) WHERE id=?', [(int) ($op === 'reschedule'), $id]);
        waitlist_signal((int) $b['clinic_id']);
    }
    event_record(
        $doctor,
        'booking.' . $op,
        [
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'patient_contacted' => !empty($data['patient_contacted']),
        ],
        $id,
    );
    notify_booking($id, $title, $body);
    if (in_array($op, ['cancelled', 'reschedule'], true)) {
        booking_sms($id, $op === 'cancelled' ? 'booking_cancelled' : 'booking_rescheduled');
    }
    return ['message' => 'تغییر نوبت ثبت شد و اعلان داخل سامانه ایجاد شد.'];
}
function doctor_bookings(int $doctor, string $from, string $to, int $clinic = 0, bool $history = false): array
{
    valid_date($from);
    valid_date($to);
    if ($to < $from || strtotime($to) - strtotime($from) > 93 * 86400) {
        throw new AuthError('بازه تقویم حداکثر ۹۳ روز است.');
    }
    $d = doctor_actor($doctor, 'view', 0, true);
    $clinics = doctor_clinics_for($doctor);
    $ids = [];
    foreach ($clinics as $c) {
        if (
            (!$clinic || (int) $c['id'] === $clinic) &&
            (!$history || $d['is_owner'] || $c['can_view_history'])
        ) {
            $ids[] = (int) $c['id'];
        }
    }
    if (!$ids) {
        return [];
    }
    // Without history permission secretaries may only view today and future appointments.
    $args = [$doctor, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00'];
    $sql =
        'SELECT b.*,s.starts_at,s.ends_at,s.service_name,s.clinic_id,c.name clinic FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics c ON c.id=s.clinic_id WHERE b.status NOT IN (\'held\',\'expired\') AND s.doctor_id=? AND s.starts_at>=? AND s.starts_at<? AND s.clinic_id IN (' .
        implode(',', $ids) .
        ')';
    if (!$d['is_owner']) {
        $past = [];
        foreach ($clinics as $c) {
            if ($c['can_view_history']) {
                $past[] = (int) $c['id'];
            }
        }
        $sql .=
            ' AND (s.starts_at>=CURDATE()' .
            ($past ? ' OR s.clinic_id IN (' . implode(',', $past) . ')' : '') .
            ')';
    }
    $rows = query($sql . ' ORDER BY s.starts_at,b.id LIMIT 2001', $args)->fetchAll();
    if (count($rows) > 2000) {
        throw new AuthError('بازه کوچک‌تر انتخاب کنید؛ تعداد نوبت‌ها بیش از ۲۰۰۰ است.');
    }
    foreach ($rows as &$r) {
        $r['starts_at_jalali'] = jdate($r['starts_at'], true);
        $r['status_label'] = booking_status_label($r['status']);
    }
    return $rows;
}
function my_notifications(): array
{
    $u = require_user();
    return query(
        "SELECT n.* FROM notifications n LEFT JOIN bookings b ON b.id=n.booking_id LEFT JOIN slots s ON s.id=b.slot_id WHERE n.user_id=? AND (n.booking_id IS NULL OR b.patient_user_id=? OR EXISTS(SELECT 1 FROM doctor_accounts a WHERE a.user_id=? AND a.doctor_id=s.doctor_id) OR EXISTS(SELECT 1 FROM staff_scopes sc WHERE sc.user_id=? AND sc.clinic_id=s.clinic_id AND sc.role='secretary' AND (s.starts_at>=CURDATE() OR sc.can_view_history=1))) ORDER BY n.id DESC LIMIT 100",
        [$u['id'], $u['id'], $u['id'], $u['id']],
    )->fetchAll();
}
function read_notification(int $id): void
{
    $u = require_user();
    query('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=?', [
        $id,
        $u['id'],
    ]);
}
/**
 * Checks an uploaded profile photo (JPEG/PNG, ≤ 3 MB, ≤ 12 megapixels) and returns getimagesize() data.
 * Used before saving, and by registration before the doctor is created (rc.19) so a bad file is reported
 * on the form instead of after the request has been sent.
 */
function doctor_photo_check(array $upload): array
{
    if (!extension_loaded('gd')) {
        throw new AuthError('افزونه GD برای مدیریت تصویر باید فعال باشد.');
    }
    if (
        ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        ($upload['size'] ?? 0) > 3 * 1024 * 1024 ||
        !is_uploaded_file($upload['tmp_name'] ?? '')
    ) {
        throw new AuthError('تصویر JPEG یا PNG با حجم حداکثر ۳ مگابایت انتخاب کنید.');
    }
    $info = @getimagesize($upload['tmp_name']);
    if (
        !$info ||
        !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) ||
        $info[0] > 5000 ||
        $info[1] > 5000 ||
        $info[0] * $info[1] > 12000000
    ) {
        throw new AuthError('فرمت یا ابعاد تصویر مجاز نیست (حداکثر ۱۲ مگاپیکسل).');
    }
    return $info;
}

function save_doctor_photo(int $doctor, array $upload): void
{
    doctor_actor($doctor, 'owner', 0, true);
    $info = doctor_photo_check($upload);
    $src =
        $info[2] === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($upload['tmp_name'])
            : @imagecreatefrompng($upload['tmp_name']);
    if (!$src) {
        throw new AuthError('تصویر قابل پردازش نیست.');
    }
    $ratio = min(1, 900 / max($info[0], $info[1]));
    $w = max(1, (int) round($info[0] * $ratio));
    $h = max(1, (int) round($info[1] * $ratio));
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    $dir = __DIR__ . '/../private/photos';
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new AuthError('ذخیره تصویر ممکن نیست.');
    }
    $file = bin2hex(random_bytes(24)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $file, 88)) {
        throw new AuthError('ذخیره تصویر ممکن نیست.');
    }
    imagedestroy($src);
    imagedestroy($dst);
    chmod($dir . '/' . $file, 0600);
    try {
        db_transaction(function () use ($doctor, $file) {
            lock_doctor($doctor);
            doctor_actor($doctor, 'owner', 0, true);
            query('UPDATE doctors SET photo_file=? WHERE id=?', [$file, $doctor]);
            event_record($doctor, 'doctor.photo', ['file' => $file]);
        });
    } catch (Throwable $e) {
        unlink($dir . '/' . $file);
        throw $e;
    }
}
