<?php

namespace App\Support;

/** Clean values pasted into single-line technical fields (tokens, URLs, keys). */
final class Paste
{
    /** Remove spaces and invisible characters; "ABCABC" (pasted twice) becomes "ABC". */
    public static function clean(string $v): string
    {
        $v = (string) preg_replace('/[\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $v);
        $n = strlen($v);
        foreach ([2, 3, 4] as $times) {
            if ($n > 0 && $n % $times === 0 && str_repeat(substr($v, 0, intdiv($n, $times)), $times) === $v) {
                return substr($v, 0, intdiv($n, $times));
            }
        }

        return $v;
    }

    /** First http(s) URL in the text (also when it was pasted twice or with surrounding text). */
    public static function url(string $v): string
    {
        $v = self::clean($v);
        if (preg_match('#https?://.+?(?=https?://|$)#i', $v, $m)) {
            return $m[0];
        }

        return $v;
    }
}
