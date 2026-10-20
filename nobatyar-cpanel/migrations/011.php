<?php
declare(strict_types=1);

/**
 * 0.10.0 — clinics and medical centers (phase 9): centers with review, branches, rooms, doctor links
 * with doctor-granted permissions, center staff, center subscriptions, announcements and an event log.
 * A doctor's work at a center branch is an ordinary clinics row marked with center_id/branch_id, so the
 * existing scheduling, booking and reception engine is reused unchanged. Additive and restartable.
 */

function migrate_v11(PDO $p): void
{
    mig_lock($p, 'v11');
    try {
        if (mig_applied($p, 11)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/011.sql');
        foreach (
            [
                ['clinics', 'center_id', 'INT UNSIGNED NULL'],
                ['clinics', 'branch_id', 'BIGINT UNSIGNED NULL'],
                ['working_hours', 'room_id', 'BIGINT UNSIGNED NULL'],
                ['staff_scopes', 'center_id', 'INT UNSIGNED NULL'],
                ['subscription_plans', 'audience', "VARCHAR(10) NOT NULL DEFAULT 'doctor'"],
            ]
            as [$table, $column, $definition]
        ) {
            mig_column($p, $table, $column, $definition);
        }
        mig_index($p, 'clinics', 'clinic_branch', 'INDEX clinic_branch(branch_id,active)');
        mig_index($p, 'clinics', 'clinic_center', 'INDEX clinic_center(center_id)');
        mig_index($p, 'working_hours', 'room_week', 'INDEX room_week(room_id,weekday,active)');
        mig_index($p, 'staff_scopes', 'scope_center', 'INDEX scope_center(center_id)');
        foreach (
            [
                'center_registration_enabled' => '1',
                'center_trial_enabled' => '0',
                'center_trial_days' => '14',
                'center_grace_days' => '3',
            ]
            as $key => $value
        ) {
            mig_setting_default($p, $key, $value);
        }
        if (!(int) $p->query("SELECT COUNT(*) FROM subscription_plans WHERE audience='center'")->fetchColumn()) {
            $insert = $p->prepare(
                "INSERT INTO subscription_plans(name,duration_days,price_toman,features,active,sort_order,audience) VALUES(?,?,0,?,0,?,'center')",
            );
            $features = "صفحه عمومی مرکز و شعبه‌ها\nپنل کارکنان، تقویم تجمیعی و مدیریت اتاق‌ها\nگزارش عملکرد پزشکان و شعبه‌ها و اطلاعیه به بیماران";
            foreach ([['مرکز — ماهانه', 30], ['مرکز — سالانه', 365]] as $i => [$name, $days]) {
                $insert->execute([$name, $days, $features, 100 + $i * 10]);
            }
        }
        mig_mark($p, 11, '0.10.0');
    } finally {
        mig_unlock($p, 'v11');
    }
}
