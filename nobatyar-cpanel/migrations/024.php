<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.21 — deleting and suspending users and doctors from the administration panel.
 * Deleting never removes rows (bookings are the doctor's records and many tables point at users and
 * doctors): the account is anonymized, deactivated and marked deleted, its mobile and medical council
 * number are released for a new registration, and it disappears from lists and the public site.
 * - users.deleted_at, users.suspended_reason (shown to the user at sign-in)
 * - doctors.deleted_at
 */

function migrate_v24(PDO $p): void
{
    mig_lock($p, 'v24');
    try {
        if (mig_applied($p, 24)) {
            return;
        }
        mig_column($p, 'users', 'deleted_at', 'DATETIME NULL');
        mig_column($p, 'users', 'suspended_reason', 'VARCHAR(255) NULL');
        mig_column($p, 'doctors', 'deleted_at', 'DATETIME NULL');
        mig_mark($p, 24, '1.0.0-rc.21');
    } finally {
        mig_unlock($p, 'v24');
    }
}
