<?php
declare(strict_types=1);

/**
 * «چک نوبت» (1.0.21): the day's appointments of one clinic for the doctor or the secretary, a lookup by national ID or
 * name, and «ثبت حضور», which is the reception step «مراجعه‌کرده» (same rules: only on the appointment day, joins the
 * queue). Pro or free is chosen by the administrator like every other feature (pro_catalog key «checkin»).
 */

/** Appointments of the clinic on one day (held/cancelled left out), with the patient's national ID decrypted. */
function checkin_rows(int $clinic, string $day): array
{
    valid_date($day);
    $rows = query(
        "SELECT b.id,b.revision,b.status,b.attendance_state,b.patient_name,b.phone,b.tracking_code,s.starts_at,s.service_name,
                COALESCE(fm.national_id_enc,rp.national_id_enc,u.national_id_enc) nid_enc
         FROM bookings b JOIN slots s ON s.id=b.slot_id
         LEFT JOIN family_members fm ON fm.id=b.family_member_id
         LEFT JOIN reception_patients rp ON rp.id=b.intake_patient_id
         LEFT JOIN users u ON u.id=b.patient_user_id AND b.family_member_id IS NULL AND b.intake_patient_id IS NULL
         WHERE s.clinic_id=? AND s.starts_at>=? AND s.starts_at<? AND b.status IN ('confirmed','completed','no_show')
         ORDER BY s.starts_at,b.id",
        [$clinic, $day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00'],
    )->fetchAll();
    foreach ($rows as &$r) {
        $nid = '';
        if ($r['nid_enc']) {
            try {
                $nid = (string) decrypt_secret($r['nid_enc']);
            } catch (Throwable $e) {
                $nid = '';
            }
        }
        unset($r['nid_enc']);
        $r['national_id'] = $nid;
        $r['state_label'] = attendance_label($r);
        $r['state'] = $r['status'] === 'confirmed' ? $r['attendance_state'] : $r['status'];
    }
    unset($r);
    return $rows;
}

/** Matches for «کد ملی یا نام و نام خانوادگی»: an exact national ID, otherwise every word of the name. */
function checkin_find(int $clinic, string $day, string $q): array
{
    $q = trim(preg_replace('/\s+/u', ' ', strtr(digits($q), ['ي' => 'ی', 'ك' => 'ک'])));
    if (mb_strlen($q) < 2) {
        throw new AuthError('کد ملی یا نام بیمار را وارد کنید.');
    }
    $rows = checkin_rows($clinic, $day);
    if (preg_match('/^[0-9]{6,12}$/', $q)) {
        return array_values(array_filter($rows, fn($r) => $r['national_id'] === $q));
    }
    $words = explode(' ', mb_strtolower($q));
    return array_values(array_filter($rows, function ($r) use ($words) {
        $name = mb_strtolower(strtr((string) $r['patient_name'], ['ي' => 'ی', 'ك' => 'ک']));
        foreach ($words as $w) {
            if (!str_contains($name, $w)) {
                return false;
            }
        }
        return true;
    }));
}

/** The row as the popup shows it. */
function checkin_public(array $r): array
{
    return [
        'id' => (int) $r['id'],
        'revision' => (int) $r['revision'],
        'name' => (string) $r['patient_name'],
        'national_id' => $r['national_id'] !== '' ? fa_number($r['national_id']) : '—',
        'when' => jdate($r['starts_at'], true),
        'service' => (string) ($r['service_name'] ?? ''),
        'tracking' => fa_number(tracking_label((string) $r['tracking_code'])),
        'state' => $r['state'],
        'state_label' => $r['state_label'],
        'can_checkin' => $r['status'] === 'confirmed' && $r['attendance_state'] === 'waiting' && substr($r['starts_at'], 0, 10) === date('Y-m-d'),
    ];
}

/** Renders the «چک نوبت» page of a panel for one clinic (?date= Jalali, default today). */
function checkin_page(int $clinic, array $clinics, string $page, array $hidden): void
{
    $c = reception_scope($clinic);
    $day = !empty($_GET['date']) ? parse_jdate(field($_GET, 'date', 10)) : date('Y-m-d');
    $locked = !doctor_has((int) $c['doctor_id'], 'checkin');
    render('doctor/checkin', [
        'doctorId' => (int) $c['doctor_id'],
        'clinic' => $clinic,
        'clinics' => $clinics,
        'day' => $day,
        'rows' => $locked ? [] : checkin_rows($clinic, $day),
        'locked' => $locked,
        'hidden' => $hidden,
        'page' => $page,
    ]);
}

/** Doctor of the clinic, after checking the viewer may see it and the feature is on for that doctor. */
function checkin_scope(int $clinic, string $permission = 'view'): array
{
    $c = reception_scope($clinic, $permission);
    if (!doctor_has((int) $c['doctor_id'], 'checkin')) {
        throw new AuthError('«چک نوبت» ویژه اشتراک پرو است.', 403);
    }
    return $c;
}
