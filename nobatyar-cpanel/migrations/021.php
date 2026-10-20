<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.13 — two-step administrator sign-in: an SMS code to the administrator's own mobile, then the
 * e-mail and password. admins.mobile is unique and starts empty; an administrator without a number signs
 * in once with the password and registers (and confirms) a mobile before entering the panel.
 */

function migrate_v21(PDO $p): void
{
    mig_lock($p, 'v21');
    try {
        if (mig_applied($p, 21)) {
            return;
        }
        mig_column($p, 'admins', 'mobile', 'VARCHAR(11) NULL');
        mig_index($p, 'admins', 'admin_mobile', 'UNIQUE INDEX admin_mobile(mobile)');
        mig_mark($p, 21, '1.0.0-rc.13');
    } finally {
        mig_unlock($p, 'v21');
    }
}
