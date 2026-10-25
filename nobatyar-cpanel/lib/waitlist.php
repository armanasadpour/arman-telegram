<?php
declare(strict_types=1);

/**
 * Waitlist: a patient asks to be told when a time opens at a clinic for a service within a date range.
 * When capacity frees up (cancellation, release, expiry or new working hours), waiting patients are told
 * in joining order, a few at a time, with a cooldown so later entries also get their turn. Telling is
 * not reserving: the first patient to book takes the time, exactly like any other booking.
 */

function waitlist_status_labels(): array
{
    return ['waiting' => 'در انتظار', 'booked' => 'رزرو انجام شد', 'cancelled' => 'لغو شد', 'expired' => 'پایان بازه'];
}

function waitlist_join(array $data): int
{
    $u = require_user();
    if (setting('waitlist_enabled', '1') !== '1') {
        throw new AuthError('صف انتظار در حال حاضر فعال نیست.', 403);
    }
    $clinic = positive_id($data['clinic_id'] ?? 0);
    $service = positive_id($data['service_id'] ?? 0);
    $c = query(
        'SELECT cl.id,cl.doctor_id,d.name doctor FROM clinics cl JOIN doctors d ON d.id=cl.doctor_id JOIN specialties sp ON sp.id=d.specialty_id JOIN cities c ON c.id=cl.city_id JOIN provinces p ON p.id=c.province_id WHERE cl.id=? AND ' .
            visible_doctor_sql() .
            ' AND ' .
            public_clinic_sql(),
        [$clinic],
    )->fetch();
    if (!$c) {
        throw new AuthError('مطب برای نوبت‌دهی فعال نیست.', 404);
    }
    if (!doctor_has((int) $c['doctor_id'], 'waitlist')) {
        throw new AuthError('صف انتظار برای این پزشک فعال نیست.', 403);
    }
    if (!query('SELECT id FROM doctor_services WHERE id=? AND doctor_id=? AND active=1 AND plan_locked=0', [$service, $c['doctor_id']])->fetch()) {
        throw new AuthError('خدمت انتخاب‌شده متعلق به این پزشک نیست.');
    }
    $from = parse_jdate(field($data, 'date_from', 20));
    $to = parse_jdate(field($data, 'date_to', 20));
    $max = setting_int('waitlist_max_days', 14, 1, 60);
    if ($from < date('Y-m-d') || $to < $from || (strtotime($to) - strtotime($from)) / 86400 >= $max) {
        throw new AuthError('بازه صف انتظار باید از امروز به بعد و حداکثر ' . fa_number($max) . ' روز باشد.');
    }
    $member = (int) ($data['family_member_id'] ?? 0);
    $name = beneficiary((int) $u['id'], $member, $u['name'] ?: field($data, 'name', 190));
    return db_transaction(function () use ($u, $c, $clinic, $service, $from, $to, $member, $name) {
        query('SELECT id FROM users WHERE id=? FOR UPDATE', [$u['id']]);
        if ((int) query("SELECT COUNT(*) FROM waitlist_entries WHERE user_id=? AND status='waiting'", [$u['id']])->fetchColumn() >= setting_int('waitlist_max_entries', 5, 1, 50)) {
            throw new AuthError('تعداد درخواست‌های فعال صف انتظار شما به سقف رسیده است.', 409);
        }
        if (query("SELECT id FROM waitlist_entries WHERE user_id=? AND clinic_id=? AND service_id=? AND COALESCE(family_member_id,0)=? AND status='waiting' AND date_from<=? AND date_to>=?", [$u['id'], $clinic, $service, $member, $to, $from])->fetch()) {
            throw new AuthError('برای همین مطب و بازه قبلاً در صف انتظار هستید.', 409);
        }
        query('INSERT INTO waitlist_entries(user_id,family_member_id,patient_name,doctor_id,clinic_id,service_id,date_from,date_to) VALUES(?,?,?,?,?,?,?,?)', [
            $u['id'],
            $member ?: null,
            $name,
            $c['doctor_id'],
            $clinic,
            $service,
            $from,
            $to,
        ]);
        $id = (int) db()->lastInsertId();
        event_record((int) $c['doctor_id'], 'waitlist.joined', ['clinic_id' => $clinic, 'from' => $from, 'to' => $to, 'entry' => $id]);
        return $id;
    });
}

function waitlist_cancel(int $id): void
{
    $u = require_user();
    $q = query("UPDATE waitlist_entries SET status='cancelled',closed_at=NOW() WHERE id=? AND user_id=? AND status='waiting'", [$id, $u['id']]);
    if (!$q->rowCount()) {
        throw new AuthError('درخواست فعالی در صف انتظار پیدا نشد.', 404);
    }
}

function my_waitlist(int $user): array
{
    return query(
        'SELECT w.*,d.name doctor,cl.name clinic,sv.name service FROM waitlist_entries w JOIN doctors d ON d.id=w.doctor_id JOIN clinics cl ON cl.id=w.clinic_id LEFT JOIN doctor_services sv ON sv.id=w.service_id WHERE w.user_id=? ORDER BY w.status=\'waiting\' DESC,w.id DESC LIMIT 50',
        [$user],
    )->fetchAll();
}

/** Processes the waitlist (one clinic after a cancellation, or every clinic from cron). */
function waitlist_process(int $clinic = 0): array
{
    $stats = ['expired' => 0, 'booked' => 0, 'notified' => 0];
    $stats['expired'] = query("UPDATE waitlist_entries SET status='expired',closed_at=NOW() WHERE status='waiting' AND date_to<CURDATE()")->rowCount();
    // An entry is fulfilled once the same patient holds a confirmed booking with that doctor inside the range.
    foreach (
        query(
            "SELECT w.id,b.id booking_id FROM waitlist_entries w JOIN bookings b ON b.patient_user_id=w.user_id AND COALESCE(b.family_member_id,0)=COALESCE(w.family_member_id,0) AND b.status='confirmed' AND b.created_at>=w.created_at JOIN slots s ON s.id=b.slot_id AND s.doctor_id=w.doctor_id AND DATE(s.starts_at) BETWEEN w.date_from AND w.date_to WHERE w.status='waiting'" .
                ($clinic ? ' AND w.clinic_id=' . (int) $clinic : ''),
        )->fetchAll()
        as $r
    ) {
        $stats['booked'] += query("UPDATE waitlist_entries SET status='booked',booking_id=?,closed_at=NOW() WHERE id=? AND status='waiting'", [$r['booking_id'], $r['id']])->rowCount();
    }
    if (setting('waitlist_enabled', '1') !== '1') {
        return $stats;
    }
    $cooldown = setting_int('waitlist_cooldown_minutes', 120, 5, 1440);
    $batch = setting_int('waitlist_batch', 3, 1, 50);
    $entries = query(
        "SELECT w.*,d.name doctor FROM waitlist_entries w JOIN doctors d ON d.id=w.doctor_id WHERE w.status='waiting' AND (w.last_notified_at IS NULL OR w.last_notified_at<DATE_SUB(NOW(),INTERVAL ? MINUTE))" .
            ($clinic ? ' AND w.clinic_id=?' : '') .
            ' ORDER BY w.id LIMIT 300',
        $clinic ? [$cooldown, $clinic] : [$cooldown],
    )->fetchAll();
    $perClinic = [];
    $cache = [];
    foreach ($entries as $w) {
        if (($perClinic[$w['clinic_id']] ?? 0) >= $batch) {
            continue;
        }
        $slot = null;
        for ($day = max(date('Y-m-d'), $w['date_from']); $day <= $w['date_to'] && !$slot; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            $key = $w['clinic_id'] . ':' . $w['service_id'] . ':' . $day;
            $cache[$key] ??= availability((int) $w['clinic_id'], (int) $w['service_id'], $day);
            $slot = $cache[$key][0] ?? null;
        }
        if (!$slot) {
            continue;
        }
        $perClinic[$w['clinic_id']] = ($perClinic[$w['clinic_id']] ?? 0) + 1;
        query('UPDATE waitlist_entries SET last_notified_at=NOW(),notify_count=notify_count+1 WHERE id=?', [$w['id']]);
        $url = 'doctor.php?id=' . (int) $w['doctor_id'] . '&clinic=' . (int) $w['clinic_id'] . '&date=' . rawurlencode(digits(jdate(substr($slot['starts_at'], 0, 10))));
        notify_user(
            (int) $w['user_id'],
            'زمان آزاد نزد ' . doctor_display_name((string) $w['doctor']),
            'برای ' . $w['patient_name'] . '، نخستین زمان آزاد ' . jdate($slot['starts_at'], true) . ' است. این پیام برای چند نفر از صف ارسال می‌شود؛ هر کس زودتر رزرو کند، زمان را می‌گیرد.',
            null,
            'waitlist',
            $url,
        );
        $phone = (string) query('SELECT mobile FROM users WHERE id=?', [$w['user_id']])->fetchColumn();
        if (sms_allowed_for((int) $w['user_id'], 'waitlist')) {
            sms_enqueue(
                'waitlist_available',
                $phone,
                ['token' => digits(jdate(substr($slot['starts_at'], 0, 10))), 'token2' => substr($slot['starts_at'], 11, 5), 'token10' => doctor_display_name((string) $w['doctor'])],
                'waitlist:' . $w['id'] . ':' . $slot['starts_at'],
                ['user_id' => (int) $w['user_id']],
            );
        }
        $stats['notified']++;
    }
    return $stats;
}

/** After a request that freed capacity, check the clinic's waitlist once the response has been sent. */
function waitlist_signal(int $clinic): void
{
    static $clinics = [];
    if (PHP_SAPI === 'cli' || setting('waitlist_enabled', '1') !== '1') {
        return;
    }
    if (!$clinics) {
        register_shutdown_function(function () use (&$clinics) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            foreach (array_keys($clinics) as $id) {
                try {
                    waitlist_process((int) $id);
                } catch (Throwable $e) {
                    error_log('nobatyar waitlist: ' . $e->getMessage());
                }
            }
        });
    }
    $clinics[$clinic] = true;
}
