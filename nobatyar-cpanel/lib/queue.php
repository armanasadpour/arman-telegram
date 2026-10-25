<?php
declare(strict_types=1);

/**
 * Reception queue and approximate visit times.
 *   - When reception marks a patient "arrived", the patient gets the next number of the day for that clinic.
 *   - "in visit" makes that number the current one; the public display shows only numbers and counts.
 *   - Delays and per-booking changes set an estimated time, shown to the patient and used for waiting estimates.
 */

/** Gives an arrived booking the next number of the day (inside the reception transaction). */
function queue_assign(int $booking, int $clinic, string $day): int
{
    query('INSERT IGNORE INTO clinic_queues(clinic_id,day) VALUES(?,?)', [$clinic, $day]);
    $q = query('SELECT * FROM clinic_queues WHERE clinic_id=? AND day=? FOR UPDATE', [$clinic, $day])->fetch();
    $existing = (int) query('SELECT queue_number FROM bookings WHERE id=?', [$booking])->fetchColumn();
    if ($existing) {
        return $existing;
    }
    $number = (int) $q['last_number'] + 1;
    query('UPDATE clinic_queues SET last_number=? WHERE clinic_id=? AND day=?', [$number, $clinic, $day]);
    query('UPDATE bookings SET queue_number=? WHERE id=?', [$number, $booking]);
    return $number;
}

function queue_call(int $booking, int $clinic, string $day): void
{
    $number = (int) query('SELECT queue_number FROM bookings WHERE id=?', [$booking])->fetchColumn();
    query('INSERT IGNORE INTO clinic_queues(clinic_id,day) VALUES(?,?)', [$clinic, $day]);
    query('UPDATE clinic_queues SET current_number=?,current_booking_id=? WHERE clinic_id=? AND day=?', [$number ?: null, $booking, $clinic, $day]);
}

/** Queue of one clinic and day without any personal data. */
function queue_state(int $clinic, string $day): array
{
    $q = query('SELECT * FROM clinic_queues WHERE clinic_id=? AND day=?', [$clinic, $day])->fetch() ?: ['current_number' => null, 'last_number' => 0];
    $rows = query(
        "SELECT b.id,b.queue_number,b.attendance_state,b.status,s.starts_at,b.estimated_at FROM bookings b JOIN slots s ON s.id=b.slot_id WHERE s.clinic_id=? AND DATE(s.starts_at)=? AND b.queue_number IS NOT NULL ORDER BY b.queue_number",
        [$clinic, $day],
    )->fetchAll();
    $waiting = array_values(array_filter($rows, fn($r) => $r['status'] === 'confirmed' && $r['attendance_state'] === 'arrived'));
    $duration = (int) query('SELECT default_duration FROM clinics WHERE id=?', [$clinic])->fetchColumn() ?: 15;
    return [
        'current' => $q['current_number'] !== null ? (int) $q['current_number'] : null,
        'waiting' => array_map(fn($r) => (int) $r['queue_number'], $waiting),
        'served' => count(array_filter($rows, fn($r) => in_array($r['status'], ['completed'], true))),
        'issued' => (int) $q['last_number'],
        'minutes_per_visit' => $duration,
        'waiting_ids' => array_map(fn($r) => (int) $r['id'], $waiting),
    ];
}

/** Position of a patient's booking today: number, how many are ahead, rough wait in minutes. */
function queue_position(array $b): ?array
{
    if (empty($b['queue_number']) || substr($b['starts_at'], 0, 10) !== date('Y-m-d')) {
        return null;
    }
    $state = queue_state((int) $b['clinic_id'], date('Y-m-d'));
    $ahead = count(array_filter($state['waiting'], fn($n) => $n < (int) $b['queue_number']));
    return [
        'number' => (int) $b['queue_number'],
        'current' => $state['current'],
        'ahead' => $ahead,
        'in_visit' => $b['attendance_state'] === 'in_visit',
        'wait_minutes' => $ahead * $state['minutes_per_visit'],
    ];
}

/** Next waiting number goes in: same rules as moving a booking to "in visit" by hand. */
function queue_call_next(int $clinic): ?int
{
    reception_scope($clinic, 'bookings');
    $state = queue_state($clinic, date('Y-m-d'));
    if (!$state['waiting_ids']) {
        return null;
    }
    $b = reception_booking($state['waiting_ids'][0], 'bookings');
    reception_change(['id' => $b['id'], 'revision' => $b['revision'], 'operation' => 'in_visit', 'reason' => 'فراخوان نوبت بعدی']);
    return (int) $b['queue_number'];
}

/** Public display token of a clinic (created on first use, can be renewed to revoke old screens). */
function queue_token(int $clinic, bool $renew = false): string
{
    reception_scope($clinic, 'bookings');
    $token = (string) query('SELECT queue_token FROM clinics WHERE id=?', [$clinic])->fetchColumn();
    if ($token === '' || $renew) {
        $token = bin2hex(random_bytes(16));
        query('UPDATE clinics SET queue_token=? WHERE id=?', [$token, $clinic]);
    }
    return $token;
}

/** Estimated time of a booking: explicit estimate if set, otherwise the original time. */
function booking_eta(array $b): string
{
    return !empty($b['estimated_at']) ? $b['estimated_at'] : $b['starts_at'];
}

/**
 * Reception changes the approximate visit time of one booking (same day, appointment time unchanged)
 * and the patient is told in the panel, by push and — if allowed — by SMS.
 */
function reception_estimate(array $data): void
{
    $id = positive_id($data['id'] ?? 0);
    $old = reception_booking($id, 'bookings');
    $time = digits(field($data, 'estimated_time', 5));
    if (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
        throw new AuthError('زمان تقریبی را به شکل ۱۰:۴۵ وارد کنید.');
    }
    $reason = field($data, 'reason', 300);
    $at = substr($old['starts_at'], 0, 10) . ' ' . $time . ':00';
    if ($old['status'] !== 'confirmed' || $old['attendance_state'] === 'in_visit') {
        throw new AuthError('فقط برای نوبت قطعی پیش از ویزیت می‌توان زمان تقریبی تعیین کرد.', 409);
    }
    db_transaction(function () use ($id, $old, $at, $reason) {
        lock_doctor((int) $old['doctor_id']);
        query('UPDATE bookings SET estimated_at=?,revision=revision+1 WHERE id=?', [$at === $old['starts_at'] ? null : $at, $id]);
        event_record((int) $old['doctor_id'], 'reception.estimate', ['clinic_id' => $old['clinic_id'], 'estimated_at' => $at, 'reason' => $reason], $id);
        notify_booking($id, 'زمان تقریبی مراجعه تغییر کرد', 'زمان تقریبی جدید: ' . fa_number(substr($at, 11, 5)) . ' (زمان اصلی نوبت ' . fa_number(substr($old['starts_at'], 11, 5)) . ') • ' . $reason, 'delay');
        if (sms_allowed_for($old['patient_user_id'] ? (int) $old['patient_user_id'] : null, 'delay')) {
            sms_enqueue('visit_time_changed', $old['phone'], ['token' => substr($at, 11, 5), 'token2' => digits(jdate(substr($at, 0, 10))), 'token10' => doctor_display_name((string) $old['doctor'])], 'estimate:' . $id . ':' . $at, [
                'booking_id' => $id,
                'user_id' => $old['patient_user_id'] ? (int) $old['patient_user_id'] : null,
            ]);
        }
    });
}

/** One line for patient views: queue position today, or the approximate time when it differs. */
function booking_live_line(array $b): string
{
    if (($b['status'] ?? '') !== 'confirmed') {
        return '';
    }
    $pos = queue_position($b);
    if ($pos) {
        if ($pos['in_visit']) {
            return '<p class="live-line now">' . icon('queue') . ' شماره ' . fa_number($pos['number']) . ' — نوبت شماست، به اتاق پزشک بروید.</p>';
        }
        return '<p class="live-line">' . icon('queue') . ' شماره نوبت شما ' . fa_number($pos['number']) .
            ($pos['current'] ? ' • شماره در حال ویزیت ' . fa_number($pos['current']) : '') .
            ' • ' . ($pos['ahead'] ? fa_number($pos['ahead']) . ' نفر جلوتر از شما (حدود ' . fa_number($pos['wait_minutes']) . ' دقیقه)' : 'نفر بعدی شما هستید') . '</p>';
    }
    if (!empty($b['estimated_at']) && $b['estimated_at'] !== $b['starts_at']) {
        return '<p class="live-line delay">' . icon('clock') . ' زمان تقریبی مراجعه: <bdi>' . esc(fa_number(substr($b['estimated_at'], 11, 5))) . '</bdi> (زمان نوبت ' . esc(fa_number(substr($b['starts_at'], 11, 5))) . ')</p>';
    }
    return '';
}
