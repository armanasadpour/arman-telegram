<?php
declare(strict_types=1);

namespace PM\Auth;

use PM\Infra\Database;
use PM\Infra\Sms\SmsException;
use PM\Infra\Sms\SmsProvider;
use PM\Settings\Settings;
use PM\Support\Clock;
use PM\Support\Crypto;
use PM\Support\Fa;
use PM\Support\Ip;
use PM\Support\Random;
use PM\Support\UserError;

/**
 * One-time codes over SMS. Proves possession of the phone number only; never used to
 * derive or recover encryption keys.
 */
final class OtpService
{
    public const COOKIE = '__Host-lc';

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly RateLimiter $limiter,
        private readonly string $key,
        private readonly ?SmsProvider $sms,
    ) {
    }

    /**
     * Starts a challenge. For unknown/inactive numbers no SMS is sent, but the caller gets an
     * indistinguishable challenge so that the response does not reveal which numbers exist.
     *
     * @return string challenge token for the browser cookie
     */
    public function start(string $phoneE164, ?int $userId, string $purpose, string $ip): string
    {
        $s = $this->settings;
        $ipOk = $this->limiter->hit('otp:ip:' . $ip, $s->int('otp.per_ip_hour'), 3600);
        $phoneOk = $this->limiter->hit('otp:phone:' . $phoneE164, $s->int('otp.per_phone_hour'), 3600);
        $last = $this->db->scalar('SELECT MAX(created_at) FROM otp_requests WHERE phone_e164 = ?', [$phoneE164]);
        $tooSoon = $last !== null && strtotime($last . ' UTC') > Clock::now()->getTimestamp() - $s->int('otp.resend_seconds');
        if (!$ipOk || !$phoneOk || $tooSoon) {
            throw new UserError('درخواست‌های زیادی ارسال شده است. لطفاً کمی بعد دوباره تلاش کنید.');
        }
        if ($this->sms === null) { // same error for known and unknown numbers
            throw new UserError('ارسال پیامک تنظیم نشده است. با مدیر سامانه تماس بگیرید.');
        }

        $challenge = Random::token(32);
        $challengeHash = Crypto::sha256($challenge);
        $code = Random::digits(6);
        $id = $this->db->insert(
            'INSERT INTO otp_requests (phone_e164, user_id, challenge_hash, purpose, code_hash, max_attempts, status, ip, created_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$phoneE164, $userId, $challengeHash, $purpose, $this->codeHash($challengeHash, $phoneE164, $code),
             $s->int('otp.max_attempts'), 'pending', Ip::toBinary($ip), Clock::db(), Clock::dbIn('+' . $s->int('otp.ttl_seconds') . ' seconds')]
        );
        if ($userId !== null && $this->sms !== null) {
            try {
                $msg = strtr((string)$s->get('otp.message'), ['{code}' => $code]);
                $ref = $this->sms->sendOtp($phoneE164, $code, $msg);
                $this->db->run('UPDATE otp_requests SET provider_ref = ? WHERE id = ?', [substr($ref, 0, 100), $id]);
            } catch (SmsException $e) {
                error_log('SMS send failed: ' . $e->getMessage());
                $this->db->run('UPDATE otp_requests SET status = ? WHERE id = ?', ['expired', $id]);
                throw new UserError('ارسال پیامک ناموفق بود. لطفاً بعداً تلاش کنید یا با مدیر تماس بگیرید.');
            }
        }
        return $challenge;
    }

    /** @return array<string, mixed>|null pending challenge row (without verifying a code) */
    public function pending(?string $challenge): ?array
    {
        if ($challenge === null || $challenge === '') {
            return null;
        }
        return $this->db->one(
            'SELECT * FROM otp_requests WHERE challenge_hash = ? AND status = ? AND expires_at > ?',
            [Crypto::sha256($challenge), 'pending', Clock::db()]
        );
    }

    /** @return int|null user id on success */
    public function verify(?string $challenge, string $code): ?int
    {
        $row = $this->pending($challenge);
        if ($row === null) {
            return null;
        }
        $code = preg_replace('/\D/', '', Fa::toLatinDigits($code)) ?? '';
        $attempts = (int)$row['attempts'] + 1;
        $ok = strlen($code) === 6 && hash_equals($row['code_hash'], $this->codeHash($row['challenge_hash'], $row['phone_e164'], $code));
        if ($ok && $row['user_id'] !== null) {
            $n = $this->db->run('UPDATE otp_requests SET status = ?, consumed_at = ?, attempts = ? WHERE id = ? AND status = ?',
                ['consumed', Clock::db(), $attempts, $row['id'], 'pending'])->rowCount();
            return $n === 1 ? (int)$row['user_id'] : null;
        }
        $status = $attempts >= (int)$row['max_attempts'] ? 'locked' : 'pending';
        $this->db->run('UPDATE otp_requests SET attempts = ?, status = ? WHERE id = ?', [$attempts, $status, $row['id']]);
        return null;
    }

    public function purge(): int
    {
        return $this->db->run('DELETE FROM otp_requests WHERE created_at < ?', [Clock::dbIn('-1 day')])->rowCount();
    }

    private function codeHash(string $challengeHash, string $phone, string $code): string
    {
        return Crypto::hmac($this->key, 'otp|' . $challengeHash . '|' . $phone . '|' . $code);
    }
}
