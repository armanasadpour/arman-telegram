<?php
declare(strict_types=1);
/** 1.0.5: per-weekday appointment length (NULL = clinic default) and the floating support-call button switch. */
function migrate_v27(PDO $p): void {
    mig_lock($p, 'v27');
    try {
        if (mig_applied($p,27)) return;
        mig_column($p,'working_hours','slot_minutes','SMALLINT UNSIGNED NULL');
        mig_setting_default($p,'support_fab','1');
        mig_mark($p,27,'1.0.5');
    } finally { mig_unlock($p,'v27'); }
}
