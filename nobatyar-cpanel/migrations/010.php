<?php
declare(strict_types=1);

/**
 * 0.9.0 — multi-gateway payments (phase 8): gateway registry with encrypted credentials and real
 * verification, booking fee rules by city/doctor, refunds, booking discounts.
 * The Zarinpal settings of 0.6–0.8 (private/payment.php) are moved into the encrypted registry;
 * because they were never verified with a real payment, the gateway starts disabled until the
 * administrator completes one successful test payment from the gateways page.
 */

require_once __DIR__ . '/../lib/crypto.php';

function migrate_v10(PDO $p): void
{
    mig_lock($p, 'v10');
    try {
        if (mig_applied($p, 10)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/010.sql');
        foreach (
            [
                ['bookings', 'discount_code_id', 'INT UNSIGNED NULL'],
                ['bookings', 'discount_toman', 'INT UNSIGNED NOT NULL DEFAULT 0'],
                ['bookings', 'fee_source', 'VARCHAR(20) NULL'],
                ['payment_transactions', 'gateway_order_id', 'BIGINT UNSIGNED NULL'],
            ]
            as [$table, $column, $definition]
        ) {
            mig_column($p, $table, $column, $definition);
        }
        $insert = $p->prepare('INSERT IGNORE INTO payment_gateways(name,sort_order) VALUES(?,?)');
        foreach (['zarinpal', 'sep', 'sadad', 'mellat', 'asanpardakht'] as $i => $name) {
            $insert->execute([$name, ($i + 1) * 10]);
        }
        foreach (
            [
                'payment_default_gateway' => 'zarinpal',
                'payment_fallback_gateway' => '',
                'payment_test_amount' => '1000',
                'booking_fee_refund_on_cancel' => '1',
            ]
            as $key => $value
        ) {
            mig_setting_default($p, $key, $value);
        }
        v10_import_zarinpal($p);
        mig_mark($p, 10, '0.9.0');
    } finally {
        mig_unlock($p, 'v10');
    }
}

function v10_import_zarinpal(PDO $p): void
{
    $file = dirname(__DIR__) . '/private/payment.php';
    if (!is_file($file)) {
        return;
    }
    $old = require $file;
    if (is_array($old) && !empty($old['merchant_id'])) {
        $config = json_encode(['merchant_id' => encrypt_secret((string) $old['merchant_id']), 'sandbox' => '0']);
        $hash = hash('sha256', json_encode(['merchant_id' => (string) $old['merchant_id'], 'sandbox' => '0']));
        $q = $p->prepare("UPDATE payment_gateways SET config=?,config_hash=?,enabled=0,last_error=? WHERE name='zarinpal' AND config IS NULL");
        $q->execute([$config, $hash, 'تنظیمات از نسخه قبلی منتقل شد؛ برای فعال‌سازی یک پرداخت آزمایشی واقعی انجام دهید.']);
    }
    @unlink($file);
}
