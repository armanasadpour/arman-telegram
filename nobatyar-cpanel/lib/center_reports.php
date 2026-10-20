<?php
declare(strict_types=1);

/**
 * Center calendar, reports and announcements. Everything is limited to the center's own clinic rows,
 * the staff member's branch, and what each doctor granted:
 *   - reports need perm_reports or perm_bookings (statistics only, never patient identity);
 *   - patient names and phones need perm_bookings (and perm_history for past visits);
 *   - announcements reach only patients of doctors who granted perm_bookings.
 */

function fa_weekdays(): array
{
    return [6 => 'شنبه', 0 => 'یکشنبه', 1 => 'دوشنبه', 2 => 'سه‌شنبه', 3 => 'چهارشنبه', 4 => 'پنجشنبه', 5 => 'جمعه'];
}

/** Shared FROM/WHERE for center bookings within the staff member's branch scope. */
function center_booking_scope(array $actor, array $filters): array
{
    $sql = " FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN doctors d ON d.id=cl.doctor_id JOIN center_branches br ON br.id=cl.branch_id JOIN center_doctors l ON l.center_id=cl.center_id AND l.doctor_id=cl.doctor_id AND l.status='active' WHERE cl.center_id=? AND b.status NOT IN ('held','expired') AND (l.perm_reports=1 OR l.perm_bookings=1)";
    $args = [(int) $actor['center']['id']];
    if ($actor['staff']['branch_id']) {
        $sql .= ' AND cl.branch_id=?';
        $args[] = (int) $actor['staff']['branch_id'];
    }
    if (!empty($filters['branch'])) {
        $sql .= ' AND cl.branch_id=?';
        $args[] = (int) $filters['branch'];
    }
    if (!empty($filters['doctor'])) {
        $sql .= ' AND cl.doctor_id=?';
        $args[] = (int) $filters['doctor'];
    }
    if (!empty($filters['status']) && in_array($filters['status'], ['confirmed', 'completed', 'no_show', 'cancelled'], true)) {
        $sql .= ' AND b.status=?';
        $args[] = $filters['status'];
    }
    $sql .= ' AND s.starts_at BETWEEN ? AND ?';
    $args[] = $filters['from'] . ' 00:00:00';
    $args[] = $filters['to'] . ' 23:59:59';
    return [$sql, $args];
}

/** Whether patient identity may be shown for one booking row. */
function center_can_see_patient(array $actor, array $row): bool
{
    $s = $actor['staff'];
    if (empty($s['perm_bookings']) || empty($row['perm_bookings'])) {
        return false;
    }
    return $row['starts_at'] >= date('Y-m-d') . ' 00:00:00' || (!empty($s['perm_history']) && !empty($row['perm_history']));
}

/** Bookings for the aggregated calendar, with room resolved from the weekly schedule. */
function center_calendar(array $actor, array $filters): array
{
    [$sql, $args] = center_booking_scope($actor, $filters);
    $rows = query(
        'SELECT b.id,b.status,b.attendance_state,b.booking_channel,b.patient_name,b.phone,b.tracking_code,s.starts_at,s.ends_at,s.service_name,cl.id clinic_id,cl.branch_id,cl.doctor_id,d.name doctor,br.name branch,l.perm_bookings,l.perm_history' .
            $sql .
            ' ORDER BY s.starts_at,br.sort_order,d.name LIMIT 2000',
        $args,
    )->fetchAll();
    $hours = [];
    foreach (query('SELECT h.clinic_id,h.weekday,h.opens_at,h.closes_at,r.name room FROM working_hours h JOIN clinics cl ON cl.id=h.clinic_id LEFT JOIN center_rooms r ON r.id=h.room_id WHERE cl.center_id=? AND h.active=1', [$actor['center']['id']])->fetchAll() as $h) {
        $hours[$h['clinic_id']][] = $h;
    }
    foreach ($rows as &$r) {
        $r['visible'] = center_can_see_patient($actor, $r);
        if (!$r['visible']) {
            $r['patient_name'] = '';
            $r['phone'] = '';
            $r['tracking_code'] = '';
        }
        $r['room'] = null;
        $w = (int) date('w', strtotime($r['starts_at']));
        $t = substr($r['starts_at'], 11, 8);
        foreach ($hours[$r['clinic_id']] ?? [] as $h) {
            if ((int) $h['weekday'] === $w && $h['opens_at'] <= $t && $h['closes_at'] > $t) {
                $r['room'] = $h['room'];
            }
        }
    }
    unset($r);
    return $rows;
}

/** Visit, no-show and cancellation statistics per doctor, per branch and per day. */
function center_report(array $actor, array $filters): array
{
    [$sql, $args] = center_booking_scope($actor, $filters);
    $agg = "COUNT(*) total,SUM(b.status='completed') completed,SUM(b.status='no_show') no_show,SUM(b.status='cancelled') cancelled,SUM(b.status='confirmed' AND s.starts_at>=NOW()) upcoming,SUM(b.status='confirmed' AND s.starts_at<NOW()) unresolved,SUM(b.booking_channel='online') online,SUM(b.booking_channel<>'online') staff";
    $rate = function (array $rows): array {
        foreach ($rows as &$r) {
            $attended = (int) $r['completed'] + (int) $r['no_show'];
            $r['no_show_rate'] = $attended ? round(100 * (int) $r['no_show'] / $attended, 1) : 0.0;
        }
        return $rows;
    };
    return [
        'totals' => $rate([query("SELECT $agg" . $sql, $args)->fetch()])[0],
        'by_doctor' => $rate(query("SELECT d.id,d.name,$agg" . $sql . ' GROUP BY d.id,d.name ORDER BY total DESC', $args)->fetchAll()),
        'by_branch' => $rate(query("SELECT br.id,br.name,$agg" . $sql . ' GROUP BY br.id,br.name ORDER BY br.sort_order', $args)->fetchAll()),
        'by_day' => $rate(query("SELECT DATE(s.starts_at) day,$agg" . $sql . ' GROUP BY DATE(s.starts_at) ORDER BY day', $args)->fetchAll()),
    ];
}

function center_date_filters(array $get, int $defaultDays = 7): array
{
    $today = date('Y-m-d');
    try {
        $from = trim((string) ($get['from'] ?? '')) !== '' ? parse_jdate((string) $get['from']) : $today;
        $to = trim((string) ($get['to'] ?? '')) !== '' ? parse_jdate((string) $get['to']) : date('Y-m-d', strtotime('+' . ($defaultDays - 1) . ' days'));
    } catch (RuntimeException $e) {
        [$from, $to] = [$today, date('Y-m-d', strtotime('+' . ($defaultDays - 1) . ' days'))];
    }
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 370) {
        $to = date('Y-m-d', strtotime($from . ' +370 days'));
    }
    return [
        'from' => $from,
        'to' => $to,
        'branch' => max(0, (int) ($get['branch'] ?? 0)),
        'doctor' => max(0, (int) ($get['doctor'] ?? 0)),
        'status' => (string) ($get['status'] ?? ''),
    ];
}

/* ---------- Announcements ---------- */

/**
 * Sends an announcement to patients with confirmed upcoming bookings in the chosen scope. Registered
 * patients get an in-app notification; with SMS selected, every phone gets one SMS through the queue
 * (template "center_announcement"). Each booking is reached at most once per announcement and channel.
 */
function center_announce(int $center, array $data): array
{
    $branch = empty($data['branch_id']) ? 0 : positive_id($data['branch_id']);
    $a = center_actor($center, 'announce', $branch);
    if ($branch) {
        center_branch($center, $branch);
    }
    $doctor = empty($data['doctor_id']) ? 0 : positive_id($data['doctor_id']);
    $from = parse_jdate(field($data, 'date_from', 20));
    $to = parse_jdate(field($data, 'date_to', 20));
    if ($from < date('Y-m-d')) {
        $from = date('Y-m-d');
    }
    if ($to < $from || (strtotime($to) - strtotime($from)) / 86400 > 60) {
        throw new AuthError('بازه اطلاعیه باید از امروز تا حداکثر ۶۰ روز بعد باشد.');
    }
    $title = field($data, 'title', 120);
    $body = field($data, 'body', 1000);
    if (mb_strlen($title) < 3 || mb_strlen($body) < 5) {
        throw new AuthError('عنوان و متن اطلاعیه را کامل بنویسید.');
    }
    $sms = !empty($data['send_sms']);
    if ($sms && !sms_dev_mode() && (!sms_enabled() || !(sms_template('center_announcement')['enabled'] ?? false))) {
        throw new AuthError('الگوی پیامک «اطلاعیه مرکز» در بخش پیامک مدیرکل فعال نشده است؛ اطلاعیه را بدون پیامک ارسال کنید.');
    }
    throttle('center_announce', 30, 86400);
    $sql =
        "SELECT b.id,b.phone,b.patient_user_id,s.starts_at,d.name doctor FROM bookings b JOIN slots s ON s.id=b.slot_id JOIN clinics cl ON cl.id=s.clinic_id JOIN doctors d ON d.id=cl.doctor_id JOIN center_doctors l ON l.center_id=cl.center_id AND l.doctor_id=cl.doctor_id AND l.status='active' AND l.perm_bookings=1 WHERE cl.center_id=? AND b.status='confirmed' AND s.starts_at>=NOW() AND s.starts_at BETWEEN ? AND ?";
    $args = [$center, $from . ' 00:00:00', $to . ' 23:59:59'];
    $scopeBranch = $branch ?: (int) $a['staff']['branch_id'];
    if ($scopeBranch) {
        $sql .= ' AND cl.branch_id=?';
        $args[] = $scopeBranch;
    }
    if ($doctor) {
        $sql .= ' AND cl.doctor_id=?';
        $args[] = $doctor;
    }
    $rows = query($sql . ' LIMIT 5000', $args)->fetchAll();
    if (!$rows) {
        throw new AuthError('در این بازه بیماری با نوبت قطعی (از پزشکانی که اجازه تماس داده‌اند) پیدا نشد.', 404);
    }
    return db_transaction(function () use ($a, $center, $scopeBranch, $doctor, $from, $to, $title, $body, $sms, $rows) {
        query('INSERT INTO center_announcements(center_id,branch_id,doctor_id,date_from,date_to,title,body,send_sms,created_by_user) VALUES(?,?,?,?,?,?,?,?,?)', [
            $center,
            $scopeBranch ?: null,
            $doctor ?: null,
            $from,
            $to,
            $title,
            $body,
            (int) $sms,
            $a['user']['id'],
        ]);
        $id = (int) db()->lastInsertId();
        $inApp = 0;
        $queued = 0;
        $phones = [];
        foreach ($rows as $r) {
            if ($r['patient_user_id']) {
                notify_user((int) $r['patient_user_id'], $a['center']['name'] . ': ' . $title, $body, (int) $r['id'], 'announcement', 'patient.php?tab=notifications');
                query("INSERT IGNORE INTO center_announcement_recipients(announcement_id,booking_id,channel) VALUES(?,?,'app')", [$id, $r['id']]);
                $inApp++;
            }
            if ($sms && !isset($phones[$r['phone']]) && sms_allowed_for($r['patient_user_id'] ? (int) $r['patient_user_id'] : null, 'announcement')) {
                $phones[$r['phone']] = true;
                $ok = sms_enqueue(
                    'center_announcement',
                    $r['phone'],
                    [
                        'token' => jdate(substr($r['starts_at'], 0, 10)),
                        'token2' => substr($r['starts_at'], 11, 5),
                        'token10' => mb_substr($a['center']['name'], 0, 60),
                        'token20' => mb_substr($title, 0, 100),
                    ],
                    'center-ann:' . $id . ':' . $r['phone'],
                    ['booking_id' => (int) $r['id']],
                );
                if ($ok) {
                    query("INSERT IGNORE INTO center_announcement_recipients(announcement_id,booking_id,channel) VALUES(?,?,'sms')", [$id, $r['id']]);
                    $queued++;
                }
            }
        }
        query('UPDATE center_announcements SET recipients=?,sms_queued=? WHERE id=?', [count($rows), $queued, $id]);
        center_event($center, 'announcement.sent', ['announcement_id' => $id, 'bookings' => count($rows), 'in_app' => $inApp, 'sms' => $queued]);
        return ['id' => $id, 'bookings' => count($rows), 'in_app' => $inApp, 'sms' => $queued];
    });
}
