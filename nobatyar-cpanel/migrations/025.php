<?php
declare(strict_types=1);
function migrate_v25(PDO $p): void {
    mig_lock($p, 'v25');
    try {
        if (mig_applied($p, 25)) return;
        mig_column($p, 'bookings', 'reminder_opt_in', 'TINYINT NOT NULL DEFAULT 0');
        mig_column($p, 'bookings', 'reminder_hours_snapshot', 'INT NOT NULL DEFAULT 24');
        mig_column($p, 'bookings', 'reminder_fee_toman', 'INT NOT NULL DEFAULT 0');
        mig_column($p, 'doctors', 'feature_overrides', 'TEXT NULL');
        mig_setting_default($p, 'patient_reminder_hours', '24');
        mig_setting_default($p, 'patient_reminder_fee', '0');
        mig_mark($p, 25, '1.0.0-rc.22');
    } finally { mig_unlock($p, 'v25'); }
}
