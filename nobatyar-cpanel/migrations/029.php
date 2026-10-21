<?php
declare(strict_types=1);
/** 1.0.19: clinic address in parts — main street (shown on doctor cards), side streets, the rest. */
function migrate_v29(PDO $p): void {
    mig_lock($p, 'v29');
    try {
        if (mig_applied($p,29)) return;
        mig_column($p,'clinics','street_main','VARCHAR(190) NULL');
        mig_column($p,'clinics','street_side','VARCHAR(300) NULL');
        mig_column($p,'clinics','address_extra','VARCHAR(500) NULL');
        mig_mark($p,29,'1.0.19');
    } finally { mig_unlock($p,'v29'); }
}
