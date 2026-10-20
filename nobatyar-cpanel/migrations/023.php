<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.20 — free plan + Pro subscription for doctors. A doctor no longer needs a subscription to be
 * listed and booked; Pro unlocks the features the administrator marks as «پرو» in the feature catalog.
 * - subscription_plans.max_services: services limit of a Pro plan (NULL = unlimited).
 * - doctors.plan_choice: plan picked at registration (free | pro); doctors.daily_summary_off: the doctor
 *   turned the end-of-day SMS off; doctors.notice_text / notice_until: announcement on the doctor page.
 * - clinics.plan_locked / doctor_services.plan_locked: above the current plan's limit — kept, but no new
 *   bookings until Pro is renewed (nothing is deleted; confirmed bookings stay).
 * - doctor_profile_views: daily profile view counts for the doctor's report.
 */

function migrate_v23(PDO $p): void
{
    mig_lock($p, 'v23');
    try {
        if (mig_applied($p, 23)) {
            return;
        }
        mig_column($p, 'subscription_plans', 'max_services', 'INT UNSIGNED NULL');
        mig_column($p, 'doctors', 'plan_choice', "VARCHAR(10) NOT NULL DEFAULT 'free'");
        mig_column($p, 'doctors', 'daily_summary_off', 'TINYINT NOT NULL DEFAULT 0');
        mig_column($p, 'doctors', 'notice_text', 'VARCHAR(500) NULL');
        mig_column($p, 'doctors', 'notice_until', 'DATE NULL');
        mig_column($p, 'clinics', 'plan_locked', 'TINYINT NOT NULL DEFAULT 0');
        mig_column($p, 'doctor_services', 'plan_locked', 'TINYINT NOT NULL DEFAULT 0');
        $p->exec('CREATE TABLE IF NOT EXISTS doctor_profile_views(doctor_id BIGINT UNSIGNED NOT NULL,day DATE NOT NULL,views INT UNSIGNED NOT NULL DEFAULT 0,PRIMARY KEY(doctor_id,day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        mig_setting_default($p, 'daily_summary_hour', '21');
        mig_mark($p, 23, '1.0.0-rc.20');
    } finally {
        mig_unlock($p, 'v23');
    }
}
