<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Simple image captcha for the login form: 5 digits drawn rotated and scaled on a noisy background (GD).
 * The answer lives only in the session (hashed), is single-use and expires after 10 minutes.
 */
class Captcha
{
    private const KEY = 'captcha';

    private const TTL = 600;

    public static function enabled(): bool
    {
        return (bool) config('panel.login_captcha', true);
    }

    /** New code for this session. */
    public static function issue(Session $session): string
    {
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= (string) random_int(2, 9);   // no 0/1: they look like O/l
        }
        $session->put(self::KEY, ['h' => hash('sha256', 'captcha|'.$code), 't' => time()]);

        return $code;
    }

    /** Checks the answer (Persian / Arabic digits accepted) and always consumes the code. */
    public static function check(Session $session, ?string $answer): bool
    {
        $stored = $session->pull(self::KEY);
        if (! is_array($stored) || time() - (int) ($stored['t'] ?? 0) > self::TTL) {
            return false;
        }
        $answer = strtr(trim((string) $answer), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return preg_match('/^\d{5}$/', $answer) === 1 && hash_equals((string) $stored['h'], hash('sha256', 'captcha|'.$answer));
    }

    /** Image for an <img src>: PNG when GD works, otherwise a distorted SVG. */
    public static function dataUri(string $code): string
    {
        try {
            $png = self::png($code);
        } catch (\Throwable) {
            $png = null;
        }

        return $png !== null && $png !== ''
            ? 'data:image/png;base64,'.base64_encode($png)
            : 'data:image/svg+xml;base64,'.base64_encode(self::svg($code));
    }

    /** GD-free fallback: digits as rotated text on a noisy background. */
    public static function svg(string $code): string
    {
        $out = '<svg xmlns="http://www.w3.org/2000/svg" width="210" height="70" viewBox="0 0 210 70"><rect width="210" height="70" fill="#f4f6fb"/>';
        for ($i = 0; $i < 7; $i++) {
            $out .= sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="rgb(%d,%d,%d)" stroke-width="%d"/>', random_int(0, 210), random_int(0, 70), random_int(0, 210), random_int(0, 70), random_int(150, 210), random_int(150, 210), random_int(190, 235), random_int(1, 2));
        }
        $x = 22;
        foreach (str_split($code) as $ch) {
            $y = random_int(42, 56);
            $out .= sprintf('<text x="%d" y="%d" font-family="Verdana,Arial,sans-serif" font-size="%d" font-weight="700" fill="rgb(%d,%d,%d)" transform="rotate(%d %d %d)">%s</text>',
                $x, $y, random_int(30, 38), random_int(20, 90), random_int(20, 70), random_int(110, 190), random_int(-22, 22), $x, $y, $ch);
            $x += random_int(32, 38);
        }
        for ($i = 0; $i < 2; $i++) {
            $out .= sprintf('<line x1="0" y1="%d" x2="210" y2="%d" stroke="rgb(%d,%d,%d)" stroke-width="2"/>', random_int(10, 60), random_int(10, 60), random_int(60, 120), random_int(60, 120), random_int(140, 200));
        }

        return $out.'</svg>';
    }

    /** PNG bytes, or null when GD is missing. */
    public static function png(string $code): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $w = 210;
        $h = 70;
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 244, 246, 251));
        for ($i = 0; $i < 7; $i++) {   // background lines
            $c = imagecolorallocate($img, random_int(150, 210), random_int(150, 210), random_int(190, 235));
            imagesetthickness($img, random_int(1, 2));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        $x = 12;
        foreach (str_split($code) as $ch) {
            // draw the digit small, then scale it up and rotate it
            $t = imagecreatetruecolor(10, 16);
            $bg = imagecolorallocate($t, 255, 0, 255);
            imagefill($t, 0, 0, $bg);
            imagecolortransparent($t, $bg);
            imagechar($t, 5, 1, 0, $ch, imagecolorallocate($t, random_int(20, 90), random_int(20, 70), random_int(110, 190)));
            $cw = random_int(28, 34);
            $chh = random_int(44, 52);
            $big = imagecreatetruecolor($cw, $chh);
            $bg2 = imagecolorallocate($big, 255, 0, 255);
            imagefill($big, 0, 0, $bg2);
            imagecolortransparent($big, $bg2);
            imagecopyresized($big, $t, 0, 0, 0, 0, $cw, $chh, 10, 16);
            $rot = imagerotate($big, random_int(-22, 22), $bg2);
            imagecolortransparent($rot, imagecolorat($rot, 0, 0));
            imagecopy($img, $rot, $x, random_int(2, max(3, $h - imagesy($rot) - 2)), 0, 0, imagesx($rot), imagesy($rot));
            $x += $cw + random_int(2, 8);
            imagedestroy($t);
            imagedestroy($big);
            imagedestroy($rot);
        }
        for ($i = 0; $i < 260; $i++) {   // dots
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($img, random_int(80, 200), random_int(80, 200), random_int(80, 220)));
        }
        for ($i = 0; $i < 2; $i++) {   // lines over the digits
            imagesetthickness($img, 2);
            imageline($img, 0, random_int(10, $h - 10), $w, random_int(10, $h - 10), imagecolorallocate($img, random_int(60, 120), random_int(60, 120), random_int(140, 200)));
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
