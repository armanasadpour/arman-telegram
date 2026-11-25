<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Installable web app: manifest, icons (uploaded once, resized with GD) and the settings page.
 * The service worker is a static file (public/sw.js) so its scope is the whole site.
 */
class PwaController extends Controller
{
    public const DEFAULTS = ['pwa.name' => 'پنل فروش', 'pwa.short_name' => 'پنل', 'pwa.theme' => '#4f46e5', 'pwa.background' => '#f5f7fb', 'pwa.start' => 'dashboard'];

    public function __construct(private Settings $settings) {}

    private function get(string $k): string
    {
        return (string) ($this->settings->get($k) ?? self::DEFAULTS[$k]);
    }

    public function manifest(): JsonResponse
    {
        $v = $this->settings->get('pwa.icon_version', '0');

        return response()->json([
            'name' => $this->get('pwa.name'), 'short_name' => $this->get('pwa.short_name'),
            'lang' => 'fa', 'dir' => 'rtl', 'display' => 'standalone', 'orientation' => 'portrait',
            'id' => url('/'), 'start_url' => ($this->get('pwa.start') === 'accounts' ? url('/accounts') : rtrim(url('/'), '/').'/').'?source=pwa', 'scope' => url('/').'/',
            'theme_color' => $this->get('pwa.theme'), 'background_color' => $this->get('pwa.background'),
            'icons' => [
                ['src' => url('/pwa/icon-192.png').'?v='.$v, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => url('/pwa/icon-512.png').'?v='.$v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => url('/pwa/icon-512.png').'?v='.$v, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function icon(int $size): Response
    {
        abort_unless(in_array($size, [180, 192, 512], true), 404);
        $file = storage_path('app/pwa/icon-'.$size.'.png');
        $png = is_file($file) ? (string) file_get_contents($file) : $this->defaultIcon($size);

        return response($png, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=604800']);
    }

    public function edit(): View
    {
        $this->authorize('settings.view');

        return view('settings.app', ['s' => array_combine(array_keys(self::DEFAULTS), array_map(fn ($k) => $this->get($k), array_keys(self::DEFAULTS))),
            'hasIcon' => is_file(storage_path('app/pwa/icon-512.png')), 'v' => $this->settings->get('pwa.icon_version', '0'), 'gd' => function_exists('imagecreatefromstring')]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('settings.update');
        $d = $request->validate([
            'name' => ['required', 'string', 'max:45'],
            'short_name' => ['required', 'string', 'max:15'],
            'theme' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'background' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'start' => ['required', 'in:dashboard,accounts'],
            'icon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
            'remove_icon' => ['nullable', 'boolean'],
        ], [], ['name' => 'نام برنامه', 'short_name' => 'نام کوتاه', 'theme' => 'رنگ نوار', 'background' => 'رنگ پس‌زمینه', 'icon' => 'آیکن']);
        foreach (['name', 'short_name', 'theme', 'background', 'start'] as $k) {
            $this->settings->set('pwa.'.$k, $d[$k]);
        }
        if ($request->hasFile('icon')) {
            $src = @imagecreatefromstring((string) file_get_contents($request->file('icon')->getRealPath()));
            if (! $src) {
                return back()->withInput()->with('error', 'تصویر خوانده نشد؛ یک فایل PNG یا JPG مربعی بارگذاری کنید.');
            }
            @mkdir(storage_path('app/pwa'), 0755, true);
            foreach ([180, 192, 512] as $size) {
                file_put_contents(storage_path('app/pwa/icon-'.$size.'.png'), $this->resize($src, $size, $d['background']));
            }
            imagedestroy($src);
            $this->settings->set('pwa.icon_version', (string) time());
        } elseif (! empty($d['remove_icon'])) {
            foreach ([180, 192, 512] as $size) {
                @unlink(storage_path('app/pwa/icon-'.$size.'.png'));
            }
            $this->settings->set('pwa.icon_version', (string) time());
        }
        $audit->log('settings.pwa', null, 'تنظیمات برنامه نصبی (PWA)');

        return back()->with('success', 'ذخیره شد. روی گوشی‌هایی که برنامه را نصب کرده‌اند، تغییر آیکن ممکن است بعد از حذف و نصب دوباره دیده شود.');
    }

    /** Square, centred, the free space filled with the background colour. */
    private function resize(\GdImage $src, int $size, string $bg): string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $dst = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = sscanf($bg, '#%02x%02x%02x');
        imagefill($dst, 0, 0, imagecolorallocate($dst, $r, $g, $b));
        $scale = min($size / $w, $size / $h);
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        imagecopyresampled($dst, $src, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagepng($dst);
        imagedestroy($dst);

        return (string) ob_get_clean();
    }

    /** No icon uploaded: a rounded tile in the theme colour with a white shield. */
    private function defaultIcon(int $size): string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        }
        $im = imagecreatetruecolor($size, $size);
        [$r, $g, $b] = sscanf($this->get('pwa.theme'), '#%02x%02x%02x');
        imagefill($im, 0, 0, imagecolorallocate($im, $r, $g, $b));
        $white = imagecolorallocate($im, 255, 255, 255);
        $c = $size / 2;
        $s = $size * 0.30;
        imagefilledpolygon($im, [(int) ($c - $s), (int) ($c - $s * 0.9), (int) $c, (int) ($c - $s * 1.2), (int) ($c + $s), (int) ($c - $s * 0.9),
            (int) ($c + $s * 0.85), (int) ($c + $s * 0.4), (int) $c, (int) ($c + $s * 1.15), (int) ($c - $s * 0.85), (int) ($c + $s * 0.4)], $white);
        imagefilledellipse($im, (int) $c, (int) ($c - $s * 0.1), (int) ($s * 0.7), (int) ($s * 0.7), imagecolorallocate($im, $r, $g, $b));
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
