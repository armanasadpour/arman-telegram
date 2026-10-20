<?php
declare(strict_types=1);

/**
 * 0.8.0 — doctor subscriptions (phase 7): plans, discount codes, proforma/invoices,
 * shared payment transactions, subscription history details. Additive and restartable.
 */

function v9_columns(): array
{
    return [
        ['subscriptions', 'plan_id', 'INT UNSIGNED NULL'],
        ['subscriptions', 'order_id', 'BIGINT UNSIGNED NULL'],
        ['subscriptions', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'manual'"],
        ['subscriptions', 'days', 'SMALLINT UNSIGNED NULL'],
        ['subscriptions', 'note', "VARCHAR(255) NOT NULL DEFAULT ''"],
        ['subscriptions', 'created_by_admin', 'BIGINT UNSIGNED NULL'],
        ['subscriptions', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'],
        ['doctors', 'trial_used_at', 'DATETIME NULL'],
    ];
}

function migrate_v9(PDO $p): void
{
    mig_lock($p, 'v9');
    try {
        if (mig_applied($p, 9)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/009.sql');
        foreach (v9_columns() as [$table, $column, $definition]) {
            mig_column($p, $table, $column, $definition);
        }
        mig_index($p, 'subscriptions', 'subscription_doctor', 'INDEX subscription_doctor(doctor_id,starts_on,ends_on)');
        foreach (
            [
                'subscription_grace_days' => '3',
                'subscription_trial_enabled' => '0',
                'subscription_trial_days' => '14',
                'subscription_warning_thresholds' => '7,3,1',
                'proforma_valid_hours' => '48',
                'invoice_vat_percent' => '0',
                'invoice_seller_name' => '',
                'invoice_seller_national_id' => '',
                'invoice_seller_economic_code' => '',
                'invoice_seller_address' => '',
                'invoice_seller_phone' => '',
            ]
            as $key => $value
        ) {
            mig_setting_default($p, $key, $value);
        }
        // Four standard plans. Prices are left to the administrator, so they start inactive.
        if (!(int) $p->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn()) {
            $insert = $p->prepare(
                'INSERT INTO subscription_plans(name,duration_days,price_toman,features,active,sort_order) VALUES(?,?,0,?,0,?)',
            );
            $features = "نمایش عمومی پروفایل و نوبت‌دهی آنلاین\nپنل منشی و تقویم نوبت‌ها\nپیامک تأیید و لغو نوبت برای بیمار";
            foreach ([['ماهانه', 30], ['سه‌ماهه', 90], ['شش‌ماهه', 180], ['سالانه', 365]] as $i => [$name, $days]) {
                $insert->execute([$name, $days, $features, ($i + 1) * 10]);
            }
        }
        mig_mark($p, 9, '0.8.0');
    } finally {
        mig_unlock($p, 'v9');
    }
}
