<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.4 — doctor search page: cached first free time per clinic (refreshed by cron and on every
 * appointment event), verified patient reviews (only for completed appointments, shown after approval),
 * doctor gender and an admin-set special badge, clinic district, and the search settings.
 * Additive and restartable; existing rows are not changed (gender and district start empty).
 */

function migrate_v17(PDO $p): void
{
    mig_lock($p, 'v17');
    try {
        if (mig_applied($p, 17)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/017.sql');
        mig_column($p, 'doctors', 'gender', 'VARCHAR(6) NULL');
        mig_column($p, 'doctors', 'badge_text', "VARCHAR(40) NOT NULL DEFAULT ''");
        mig_column($p, 'clinics', 'district', "VARCHAR(80) NOT NULL DEFAULT ''");
        mig_index($p, 'clinics', 'clinic_district', 'INDEX clinic_district(city_id,district)');
        mig_index($p, 'doctors', 'doctor_gender', 'INDEX doctor_gender(gender)');
        mig_setting_default($p, 'search_config', '{}');
        mig_mark($p, 17, '1.0.0-rc.4');
    } finally {
        mig_unlock($p, 'v17');
    }
}
