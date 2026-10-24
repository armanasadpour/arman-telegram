<?php
declare(strict_types=1);
/** 1.0.29: an announcement audience is a group plus an optional city («key@city»). */
function migrate_v31(PDO $p): void {
    mig_lock($p, 'v31');
    try {
        if (mig_applied($p,31)) return;
        $p->exec('ALTER TABLE admin_announcements MODIFY audience VARCHAR(60) NOT NULL');
        mig_mark($p,31,'1.0.29');
    } finally { mig_unlock($p,'v31'); }
}
