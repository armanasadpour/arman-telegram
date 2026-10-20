<?php
declare(strict_types=1);

/**
 * 1.0.0-rc.17 — VAT on the patient booking fee. The percent (Settings → «درآمد و مالیات») is fixed on the
 * booking when payment starts: bookings.vat_percent / vat_toman, and the share of each payment that is VAT
 * (payment_transactions.vat_toman) so revenue reports leave it out. Existing rows get 0 (no VAT).
 */

function migrate_v22(PDO $p): void
{
    mig_lock($p, 'v22');
    try {
        if (mig_applied($p, 22)) {
            return;
        }
        mig_column($p, 'bookings', 'vat_percent', 'DECIMAL(5,2) NOT NULL DEFAULT 0');
        mig_column($p, 'bookings', 'vat_toman', 'INT UNSIGNED NOT NULL DEFAULT 0');
        mig_column($p, 'payment_transactions', 'vat_toman', 'INT UNSIGNED NOT NULL DEFAULT 0');
        mig_setting_default($p, 'subscriptions_enabled', '1');
        mig_mark($p, 22, '1.0.0-rc.17');
    } finally {
        mig_unlock($p, 'v22');
    }
}
