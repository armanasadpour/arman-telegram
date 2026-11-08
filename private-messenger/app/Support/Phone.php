<?php
declare(strict_types=1);

namespace PM\Support;

final class Phone
{
    /** Normalizes to E.164. Iranian local formats (09xx…, 9xx…, 0098…) become +98…. Returns null if invalid. */
    public static function normalize(string $raw): ?string
    {
        $s = preg_replace('/[\s\-().]/', '', Fa::toLatinDigits(trim($raw))) ?? '';
        if (str_starts_with($s, '00')) {
            $s = '+' . substr($s, 2);
        }
        if (preg_match('/^09\d{9}$/', $s)) {
            $s = '+98' . substr($s, 1);
        } elseif (preg_match('/^9\d{9}$/', $s)) {
            $s = '+98' . $s;
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $s) ? $s : null;
    }

    public static function mask(?string $e164): string
    {
        if (!$e164) {
            return '—';
        }
        return substr($e164, 0, 6) . str_repeat('•', max(0, strlen($e164) - 9)) . substr($e164, -3);
    }
}
