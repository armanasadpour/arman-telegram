<?php
declare(strict_types=1);

namespace PM\Support;

/**
 * Password hashing for AUTHENTICATION only. Passwords are never used to derive
 * encryption keys (the server sees the password at login).
 */
final class Passwords
{
    public const MIN_LENGTH = 10;
    private const DUMMY_HASH_SOURCE = 'timing-equalizer-not-a-real-password';
    private static ?string $dummy = null;

    public static function algo(): string|int
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    public static function hash(string $password): string
    {
        return password_hash($password, self::algo());
    }

    public static function verify(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            // Spend the same time as a real check so unknown usernames are not distinguishable by timing.
            self::$dummy ??= password_hash(self::DUMMY_HASH_SOURCE, self::algo());
            password_verify($password, self::$dummy);
            return false;
        }
        return password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, self::algo());
    }

    /** @return string|null Persian error message, or null when acceptable. */
    public static function policyError(string $password, string $username = ''): ?string
    {
        if (mb_strlen($password) < self::MIN_LENGTH) {
            return 'رمز عبور باید حداقل ' . Fa::digits(self::MIN_LENGTH) . ' کاراکتر باشد.';
        }
        if (mb_strlen($password) > 256) {
            return 'رمز عبور بیش از حد طولانی است.';
        }
        if ($username !== '' && mb_strtolower($password) === mb_strtolower($username)) {
            return 'رمز عبور نباید با نام کاربری یکسان باشد.';
        }
        if (count(array_unique(mb_str_split($password))) < 4) {
            return 'رمز عبور بیش از حد ساده است.';
        }
        return null;
    }
}
