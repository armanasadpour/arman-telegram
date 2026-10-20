<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.11 — patient details for the clinic's daily list: full Jalali birth date (day, month, year),
 * basic insurance type, and the national ID asked of every patient (the account holder and anyone booked
 * for). Secretary-registered patients may carry the same details. Additive and restartable: existing rows
 * keep their values; new fields start empty and are asked once at the next booking.
 */

function migrate_v20(PDO $p): void
{
    mig_lock($p, 'v20');
    try {
        if (mig_applied($p, 20)) {
            return;
        }
        mig_column($p, 'users', 'birth_date', 'DATE NULL');
        mig_column($p, 'users', 'insurance', 'VARCHAR(20) NULL');
        mig_column($p, 'family_members', 'insurance', 'VARCHAR(20) NULL');
        mig_column($p, 'reception_patients', 'gender', 'VARCHAR(6) NULL');
        mig_column($p, 'reception_patients', 'birth_date', 'DATE NULL');
        mig_column($p, 'reception_patients', 'id_type', 'VARCHAR(10) NULL');
        mig_column($p, 'reception_patients', 'national_id_enc', 'TEXT NULL');
        mig_column($p, 'reception_patients', 'insurance', 'VARCHAR(20) NULL');
        mig_column($p, 'reception_patients', 'city_id', 'INT UNSIGNED NULL');
        // The national ID is now always asked; "off" (the earlier default) becomes "required".
        mig_setting_default($p, 'patient_id_mode', 'required');
        $p->exec("UPDATE settings SET value='required' WHERE `key`='patient_id_mode' AND value NOT IN ('optional','required')");
        mig_mark($p, 20, '1.0.0-rc.11');
    } finally {
        mig_unlock($p, 'v20');
    }
}
