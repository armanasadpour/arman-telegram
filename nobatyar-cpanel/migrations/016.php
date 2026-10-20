<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.3 — mobile side menu and the bottom-sheet sign-in: separate first and last name, the time the
 * account holder accepted the terms, and the admin-managed banner at the top of the phone menu.
 * Existing names are kept in users.name and copied into first/last name only when both are empty.
 * Additive and restartable.
 */

function migrate_v16(PDO $p): void
{
    mig_lock($p, 'v16');
    try {
        if (mig_applied($p, 16)) {
            return;
        }
        mig_column($p, 'users', 'first_name', "VARCHAR(100) NOT NULL DEFAULT ''");
        mig_column($p, 'users', 'last_name', "VARCHAR(100) NOT NULL DEFAULT ''");
        mig_column($p, 'users', 'terms_accepted_at', 'DATETIME NULL');
        // A single stored name splits at the first space; the full name in users.name never changes here.
        $p->exec(
            "UPDATE users SET first_name=TRIM(SUBSTRING_INDEX(TRIM(name),' ',1)),last_name=TRIM(SUBSTRING(TRIM(name),CHAR_LENGTH(SUBSTRING_INDEX(TRIM(name),' ',1))+1)) WHERE first_name='' AND last_name='' AND TRIM(name)<>''",
        );
        mig_setting_default($p, 'drawer_banner', '{}');
        mig_mark($p, 16, '1.0.0-rc.3');
    } finally {
        mig_unlock($p, 'v16');
    }
}
