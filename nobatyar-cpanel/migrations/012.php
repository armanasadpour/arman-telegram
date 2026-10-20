<?php
declare(strict_types=1);

/**
 * 0.11.0 — notifications and queues (phase 10): notification categories and links, delivery preferences
 * and consent, Web Push subscriptions and queue (VAPID keys generated here), waitlist, reception queue
 * numbers, estimated visit times and the public queue display token. Additive and restartable.
 */

require_once __DIR__ . '/../lib/crypto.php';

function migrate_v12(PDO $p): void
{
    mig_lock($p, 'v12');
    try {
        if (mig_applied($p, 12)) {
            return;
        }
        mig_sql_file($p, __DIR__ . '/012.sql');
        foreach (
            [
                ['notifications', 'category', "VARCHAR(20) NOT NULL DEFAULT 'general'"],
                ['notifications', 'url', 'VARCHAR(255) NULL'],
                ['bookings', 'queue_number', 'SMALLINT UNSIGNED NULL'],
                ['bookings', 'estimated_at', 'DATETIME NULL'],
                ['bookings', 'reminded_at', 'DATETIME NULL'],
                ['clinics', 'queue_token', 'CHAR(32) NULL'],
            ]
            as [$table, $column, $definition]
        ) {
            mig_column($p, $table, $column, $definition);
        }
        mig_index($p, 'clinics', 'queue_token', 'UNIQUE KEY queue_token(queue_token)');
        mig_index($p, 'bookings', 'booking_queue', 'INDEX booking_queue(queue_number)');
        foreach (
            [
                'reminder_hours' => '24,2',
                'reminder_sms_enabled' => '1',
                'push_enabled' => '1',
                'push_ttl_seconds' => '86400',
                'waitlist_enabled' => '1',
                'waitlist_max_days' => '14',
                'waitlist_cooldown_minutes' => '120',
                'waitlist_batch' => '3',
                'waitlist_max_entries' => '5',
            ]
            as $key => $value
        ) {
            mig_setting_default($p, $key, $value);
        }
        v12_vapid_keys($p);
        mig_mark($p, 12, '0.11.0');
    } finally {
        mig_unlock($p, 'v12');
    }
}

/** One VAPID key pair per installation; the private key is stored encrypted like other secrets. */
function v12_vapid_keys(PDO $p): void
{
    if ($p->query("SELECT value FROM settings WHERE `key`='push_vapid_public'")->fetchColumn()) {
        return;
    }
    if (!function_exists('openssl_pkey_new')) {
        return;
    }
    $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$key) {
        return;
    }
    $d = openssl_pkey_get_details($key)['ec'];
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    $public = $b64("\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT));
    $private = encrypt_secret($b64(str_pad($d['d'], 32, "\0", STR_PAD_LEFT)));
    $q = $p->prepare('INSERT INTO settings(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)');
    $q->execute(['push_vapid_public', $public]);
    $q->execute(['secret.push_vapid_private', $private]);
}
