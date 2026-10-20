<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.11 — the clinic's daily appointment list as an Excel file for the doctor and the secretary:
 * row, Mr/Ms, full name, national ID, birth date, insurance, phone, appointment date and time, province,
 * city. Access follows doctor_bookings(): the doctor sees every clinic, a secretary only the clinics she
 * works for, and past days only with the history permission. Cancelled appointments are left out.
 * The full national ID appears only in this file (pages show the last four digits); every export is
 * recorded in the activity log.
 */

function day_export_headers(): array
{
    return ['ردیف', 'آقا / خانم', 'نام و نام خانوادگی', 'کد ملی', 'تاریخ تولد', 'نوع بیمه', 'شماره تماس', 'تاریخ و ساعت نوبت', 'استان', 'شهر'];
}

/** Latin digits for numbers people copy into other systems (insurance portals, phone). */
function day_export_digits(string $v): string
{
    return digits($v);
}

function day_export_identity(?string $enc): string
{
    if (!$enc) {
        return '';
    }
    try {
        return (string) decrypt_secret($enc);
    } catch (Throwable $e) {
        return '';
    }
}

/** Rows for the given bookings (already permission-checked), in appointment order. */
function day_export_rows(array $bookings): array
{
    $ids = fn(string $key) => array_values(array_unique(array_filter(array_map(fn($b) => (int) ($b[$key] ?? 0), $bookings))));
    $load = function (string $sql, array $list): array {
        if (!$list) {
            return [];
        }
        $out = [];
        foreach (query(sprintf($sql, implode(',', array_fill(0, count($list), '?'))), $list)->fetchAll() as $r) {
            $out[(int) $r['id']] = $r;
        }
        return $out;
    };
    $place = 'LEFT JOIN cities ci ON ci.id=x.city_id LEFT JOIN provinces pr ON pr.id=ci.province_id';
    $users = $load("SELECT x.id,x.name,x.gender,x.birth_date,x.id_type,x.national_id_enc,x.insurance,ci.name city,pr.name province FROM users x $place WHERE x.id IN (%s)", $ids('patient_user_id'));
    $members = $load('SELECT id,name,gender,birth_date,id_type,national_id_enc,insurance FROM family_members WHERE id IN (%s)', $ids('family_member_id'));
    $intake = $load("SELECT x.id,x.name,x.mobile,x.gender,x.birth_date,x.id_type,x.national_id_enc,x.insurance,ci.name city,pr.name province FROM reception_patients x $place WHERE x.id IN (%s)", $ids('intake_patient_id'));
    $genders = ['male' => 'آقا', 'female' => 'خانم'];
    $rows = [];
    foreach ($bookings as $b) {
        $owner = $users[(int) ($b['patient_user_id'] ?? 0)] ?? null;
        $member = $members[(int) ($b['family_member_id'] ?? 0)] ?? null;
        $walkIn = $intake[(int) ($b['intake_patient_id'] ?? 0)] ?? null;
        // Who the visit is for: the family member, else the secretary's record, else the account holder.
        $who = $member ?: ($walkIn ?: $owner);
        $place = $walkIn && !$member ? $walkIn : $owner;
        $rows[] = [
            count($rows) + 1,
            $genders[$who['gender'] ?? ''] ?? '',
            (string) ($who['name'] ?? '') !== '' ? (string) $who['name'] : (string) $b['patient_name'],
            ($who['id_type'] ?? '') === 'foreign'
                ? 'اتباع خارجی' . (($n = day_export_identity($who['national_id_enc'] ?? null)) !== '' ? ' — ' . $n : '')
                : day_export_digits(day_export_identity($who['national_id_enc'] ?? null)),
            !empty($who['birth_date']) ? day_export_digits(jdate($who['birth_date'])) : '',
            insurance_label($who['insurance'] ?? null),
            day_export_digits((string) ($b['phone'] ?: ($walkIn['mobile'] ?? ''))),
            day_export_digits(jdate($b['starts_at'], true)),
            (string) ($place['province'] ?? ''),
            (string) ($place['city'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * Sends the Excel file of one day's appointments for a doctor ($clinic 0 = every clinic the viewer may see).
 * Throws AuthError when the viewer has no access.
 */
function day_export_send(int $doctor, string $day, int $clinic = 0): never
{
    valid_date($day);
    $d = doctor_actor($doctor, 'view', $clinic, true);
    require_feature($doctor, 'day_export'); // rc.20: Pro feature by default
    $bookings = array_values(array_filter(
        doctor_bookings($doctor, $day, $day, $clinic),
        fn($b) => in_array($b['status'], ['confirmed', 'completed', 'no_show'], true),
    ));
    require_once __DIR__ . '/export.php';
    audit('export.day_list', $doctor, $day . ($clinic ? ' clinic ' . $clinic : '') . ' • ' . count($bookings) . ' rows');
    export_table(
        'appointments-' . $day . '-doctor-' . $doctor,
        day_export_headers(),
        day_export_rows($bookings),
        [3, 4, 6, 7],
    );
}

/** Link + button markup used by the doctor and secretary pages. */
function day_export_button(string $href, string $day, int $doctor = 0): string
{
    if ($doctor && !doctor_has($doctor, 'day_export')) {
        return pro_lock($doctor, 'day_export', 'اکسل نوبت‌های روز');
    }
    return '<a class="button day-export" href="' . esc($href) . '" download>' . icon('download') .
        '<span>دریافت اکسل نوبت‌های ' . ($day === date('Y-m-d') ? 'امروز' : esc(jdate($day))) . '</span></a>';
}
