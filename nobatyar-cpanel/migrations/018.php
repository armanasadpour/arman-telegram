<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.5 — doctor profile and booking steps: patient details (birth year, gender, optional national ID
 * stored encrypted), the same for family members, clinic notes and required documents written by the
 * doctor, informational visit fee per service, and booking page settings. Additive and restartable;
 * existing rows keep their values and new fields start empty.
 */

function migrate_v18(PDO $p): void
{
    mig_lock($p, 'v18');
    try {
        if (mig_applied($p, 18)) {
            return;
        }
        mig_column($p, 'users', 'birth_year', 'SMALLINT UNSIGNED NULL');
        mig_column($p, 'users', 'gender', 'VARCHAR(6) NULL');
        mig_column($p, 'users', 'id_type', 'VARCHAR(10) NULL');
        mig_column($p, 'users', 'national_id_enc', 'TEXT NULL');
        mig_column($p, 'family_members', 'gender', 'VARCHAR(6) NULL');
        mig_column($p, 'family_members', 'id_type', 'VARCHAR(10) NULL');
        mig_column($p, 'family_members', 'national_id_enc', 'TEXT NULL');
        mig_column($p, 'clinics', 'notes', 'TEXT NULL');
        mig_column($p, 'clinics', 'required_docs', 'TEXT NULL');
        mig_column($p, 'doctor_services', 'visit_fee_toman', 'INT UNSIGNED NULL');
        foreach (['booking_days' => '14', 'patient_id_mode' => 'off', 'booking_payment_note' => '', 'booking_profile_fields' => '1'] as $k => $v) {
            mig_setting_default($p, $k, $v);
        }
        mig_mark($p, 18, '1.0.0-rc.5');
    } finally {
        mig_unlock($p, 'v18');
    }
}
