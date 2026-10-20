<?php
declare(strict_types=1);

/**
 * 0.12.0 — administration (phase 11): administrator roles and account state, richer activity log,
 * support tickets, homepage banners and content, platform announcements, indexes for the business
 * dashboard. Additive and restartable; every existing administrator becomes an "owner".
 */

function migrate_v13(PDO $p): void
{
    mig_lock($p, 'v13');
    try {
        if (mig_applied($p, 13)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/013.sql');
        foreach (
            [
                ['admins', 'name', "VARCHAR(120) NOT NULL DEFAULT ''"],
                ['admins', 'role', "VARCHAR(20) NOT NULL DEFAULT 'owner'"],
                ['admins', 'active', 'TINYINT NOT NULL DEFAULT 1'],
                ['admins', 'session_version', 'INT UNSIGNED NOT NULL DEFAULT 1'],
                ['admins', 'last_login_at', 'DATETIME NULL'],
                ['admins', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'],
                ['audit_log', 'ip', 'VARCHAR(45) NULL'],
                ['audit_log', 'detail', 'VARCHAR(255) NULL'],
            ]
            as [$table, $column, $definition]
        ) {
            mig_column($p, $table, $column, $definition);
        }
        mig_index($p, 'audit_log', 'audit_admin', 'INDEX audit_admin(actor_admin_id,created_at)');
        mig_index($p, 'audit_log', 'audit_action', 'INDEX audit_action(action,created_at)');
        mig_index($p, 'users', 'user_created', 'INDEX user_created(created_at)');
        mig_index($p, 'doctors', 'doctor_created', 'INDEX doctor_created(created_at)');
        mig_index($p, 'payment_transactions', 'tx_verified', 'INDEX tx_verified(verified_at)');
        mig_index($p, 'bookings', 'booking_created', 'INDEX booking_created(created_at)');
        foreach (
            [
                'site_name' => 'نوبت‌یار',
                'support_phone' => '',
                'support_email' => '',
                'support_hours' => '',
                'home_hero_title' => '',
                'home_hero_text' => '',
                'home_notice' => '',
                'footer_text' => '',
                'ticket_max_open' => '3',
                'announcement_batch' => '500',
            ]
            as $key => $value
        ) {
            mig_setting_default($p, $key, $value);
        }
        mig_mark($p, 13, '0.12.0');
    } finally {
        mig_unlock($p, 'v13');
    }
}
