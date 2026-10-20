<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.7 — tracking codes become unique 12-digit numbers (easy to read, type and say on the phone).
 * Every existing code is kept in bookings.legacy_tracking_code and still accepted where a code is looked
 * up (the reception claim link sent before the update), so nothing already given to patients breaks.
 * Restartable: only rows whose code is not yet 12 digits are converted.
 */

function migrate_v19(PDO $p): void
{
    mig_lock($p, 'v19');
    try {
        if (mig_applied($p, 19)) {
            return;
        }
        mig_column($p, 'bookings', 'legacy_tracking_code', 'VARCHAR(32) NULL');
        mig_index($p, 'bookings', 'booking_legacy_tracking', 'INDEX booking_legacy_tracking(legacy_tracking_code)');
        $used = array_flip($p->query("SELECT tracking_code FROM bookings WHERE tracking_code REGEXP '^[0-9]{12}$'")->fetchAll(PDO::FETCH_COLUMN));
        $rows = $p->query("SELECT id,tracking_code FROM bookings WHERE tracking_code NOT REGEXP '^[0-9]{12}$' ORDER BY id")->fetchAll(PDO::FETCH_NUM);
        $up = $p->prepare('UPDATE bookings SET legacy_tracking_code=COALESCE(legacy_tracking_code,tracking_code),tracking_code=? WHERE id=?');
        foreach ($rows as [$id, $old]) {
            do {
                $code = (string) random_int(100000000000, 999999999999);
            } while (isset($used[$code]));
            $used[$code] = true;
            $up->execute([$code, $id]);
        }
        mig_mark($p, 19, '1.0.0-rc.7');
    } finally {
        mig_unlock($p, 'v19');
    }
}
