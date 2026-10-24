<?php
declare(strict_types=1);
/** 1.0.28: last doctor registration each administrator has seen, for the dashboard «new doctor» popup. */
function migrate_v30(PDO $p): void {
    mig_lock($p, 'v30');
    try {
        if (mig_applied($p,30)) return;
        if (!mig_has_column($p,'admins','seen_doctor_id')) {
            mig_column($p,'admins','seen_doctor_id','BIGINT UNSIGNED NOT NULL DEFAULT 0');
            // Registrations before the update are not «new»; they stay in the dashboard's pending list.
            $p->exec('UPDATE admins SET seen_doctor_id=(SELECT COALESCE(MAX(id),0) FROM doctors)');
        }
        mig_mark($p,30,'1.0.28');
    } finally { mig_unlock($p,'v30'); }
}
