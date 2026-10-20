<?php
declare(strict_types=1);

/**
 * 1.0.0 — release (phase 12): settings for database backups. Logs and backups are files under
 * private/ (closed to the web), so no table is added. Additive and restartable.
 */

function migrate_v14(PDO $p): void
{
    mig_lock($p, 'v14');
    try {
        if (mig_applied($p, 14)) {
            return;
        }
        mig_setting_default($p, 'backup_auto', '0');
        mig_setting_default($p, 'backup_keep', '7');
        mig_mark($p, 14, '1.0.0-rc.1');
    } finally {
        mig_unlock($p, 'v14');
    }
}
