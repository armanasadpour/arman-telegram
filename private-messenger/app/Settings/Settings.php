<?php
declare(strict_types=1);

namespace PM\Settings;

use PM\Infra\Database;
use PM\Support\Clock;
use PM\Support\Crypto;

/**
 * Key/value settings in system_settings. Secret values (e.g. SMS API key) are sealed
 * with the application key so that a database dump alone does not reveal them.
 */
final class Settings
{
    public const AUTH_METHODS = [
        'password' => 'نام کاربری و رمز عبور',
        'otp' => 'شماره موبایل و کد پیامکی (OTP)',
        'password_otp' => 'رمز عبور + کد پیامکی (دومرحله‌ای)',
    ];

    public const DEFAULTS = [
        'auth.method' => 'password',
        'session.days' => 30,
        'otp.ttl_seconds' => 120,
        'otp.max_attempts' => 5,
        'otp.resend_seconds' => 60,
        'otp.per_phone_hour' => 5,
        'otp.per_ip_hour' => 20,
        'otp.message' => 'کد: {code}',
        'login.per_ip_15min' => 30,
        'login.user_fail_lock' => 10,
        'login.lock_minutes' => 15,
        'sms.provider' => 'none',
        'sms.template' => '',
        'sms.param_name' => 'CODE',
        'sms.generic_method' => 'POST',
        'sms.generic_url' => '',
        'sms.generic_body' => '{"to":"{phone}","text":"{message}"}',
        'sms.generic_auth_header' => '',
        'app.internal_name' => 'پیام‌رسان',
        'calls.stun' => 'stun:stun.l.google.com:19302', // public STUN (reveals the caller's IP to its operator); editable
        'calls.turn_urls' => '',
        'calls.turn_mode' => 'none',     // none | secret (coturn use-auth-secret) | static
        'calls.turn_username' => '',
        'calls.relay_only' => false,
        'files.max_bytes' => 0,          // 0 = no software limit (real limit: disk/quota)
        'storage.quota_bytes' => 0,      // hosting quota entered by the admin (PHP cannot read cPanel quotas); 0 = unknown
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db, private readonly string $key)
    {
    }

    public function get(string $key): mixed
    {
        $this->load();
        return array_key_exists($key, $this->cache) ? $this->cache[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public function int(string $key): int
    {
        return (int)$this->get($key);
    }

    public function set(string $key, mixed $value, ?int $adminId = null): void
    {
        $this->db->run(
            'INSERT INTO system_settings (setting_key, setting_value, updated_at, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$key, json_encode($value, JSON_UNESCAPED_UNICODE), Clock::db(), $adminId]
        );
        $this->cache = null;
    }

    public function setSecret(string $key, string $plain, ?int $adminId = null): void
    {
        $this->set('secret.' . $key, $plain === '' ? '' : base64_encode(Crypto::seal($this->key, $plain)), $adminId);
    }

    public function secret(string $key): string
    {
        $v = (string)$this->get('secret.' . $key);
        if ($v === '') {
            return '';
        }
        $raw = base64_decode($v, true);
        return $raw === false ? '' : (string)Crypto::open($this->key, $raw);
    }

    public function hasSecret(string $key): bool
    {
        return (string)$this->get('secret.' . $key) !== '';
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->db->all('SELECT setting_key, setting_value FROM system_settings') as $row) {
            $this->cache[$row['setting_key']] = json_decode((string)$row['setting_value'], true);
        }
    }
}
