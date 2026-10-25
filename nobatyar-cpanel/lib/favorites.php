<?php
declare(strict_types=1);

/**
 * 1.0.30 saved doctors: a patient keeps a short list of doctors (heart on the doctor's page) to book again quickly.
 * Only publicly visible doctors can be saved or listed; a doctor that leaves the site drops off the list.
 */

const FAVORITE_LIMIT = 100;

/** Saves or removes a doctor for the signed-in user; returns whether the doctor is saved afterwards. */
function favorite_toggle(int $doctor): bool
{
    $u = require_user();
    throttle('favorite_toggle', 120, 3600);
    if (query('DELETE FROM favorite_doctors WHERE user_id=? AND doctor_id=?', [$u['id'], $doctor])->rowCount()) {
        return false;
    }
    if (!query('SELECT 1 FROM doctors d JOIN specialties sp ON sp.id=d.specialty_id WHERE d.id=? AND ' . visible_doctor_sql(), [$doctor])->fetchColumn()) {
        throw new AuthError('پزشک پیدا نشد.', 404);
    }
    if ((int) query('SELECT COUNT(*) FROM favorite_doctors WHERE user_id=?', [$u['id']])->fetchColumn() >= FAVORITE_LIMIT) {
        throw new AuthError('حداکثر ' . fa_number(FAVORITE_LIMIT) . ' پزشک را می‌توانید ذخیره کنید.');
    }
    query('INSERT IGNORE INTO favorite_doctors(user_id,doctor_id) VALUES(?,?)', [$u['id'], $doctor]);
    return true;
}

function favorite_saved(int $user, int $doctor): bool
{
    return (bool) query('SELECT 1 FROM favorite_doctors WHERE user_id=? AND doctor_id=?', [$user, $doctor])->fetchColumn();
}

/** The user's saved doctors as doctor-card rows (newest first), each with its main public clinic and first free time. */
function favorite_cards(int $user): array
{
    $rows = query(
        'SELECT d.id,d.name,d.photo_file,d.medical_code,d.professional_title,d.subscription_until,d.feature_overrides,sp.name specialty FROM favorite_doctors f
         JOIN doctors d ON d.id=f.doctor_id JOIN specialties sp ON sp.id=d.specialty_id
         WHERE f.user_id=? AND ' . visible_doctor_sql() . ' ORDER BY f.created_at DESC LIMIT ' . FAVORITE_LIMIT,
        [$user],
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $cl = query(
            'SELECT cl.id clinic_id,cl.name clinic_name,cl.address,cl.street_main,c.name city,p.name province FROM clinics cl JOIN cities c ON c.id=cl.city_id
             JOIN provinces p ON p.id=c.province_id WHERE cl.doctor_id=? AND ' . public_clinic_sql() . ' ORDER BY cl.is_primary DESC,cl.id LIMIT 1',
            [(int) $r['id']],
        )->fetch();
        if ($cl) {
            $out[] = $r + $cl + ['first_available' => first_available((int) $cl['clinic_id'], 14)];
        }
    }
    return $out;
}
