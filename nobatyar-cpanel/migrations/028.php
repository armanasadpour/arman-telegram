<?php
declare(strict_types=1);
/** 1.0.6: date range of the weekly schedule (NULL = from now / without end). */
function migrate_v28(PDO $p): void {
    mig_lock($p, 'v28');
    try {
        if (mig_applied($p,28)) return;
        mig_column($p,'working_hours','valid_from','DATE NULL');
        mig_column($p,'working_hours','valid_until','DATE NULL');
        mig_mark($p,28,'1.0.6');
    } finally { mig_unlock($p,'v28'); }
}
