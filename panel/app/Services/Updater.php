<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Installs an uploaded release ZIP (full package or small update) over the running panel, without File Manager.
 *   - full package: contains vendor/autoload.php; installable on any version.
 *   - small update: contains update-manifest.json {"from": "1.2.4", "to": "1.2.5", "deleted": [...]}; only on "from".
 * Never touched: .env, storage/ (uploads, logs, sessions, install lock). Paths are checked before anything is written.
 * Database changes run afterwards in a fresh request (the new code), via the normal upgrade action.
 */
class Updater
{
    private const SKIP = ['#^\.env#', '#^storage/#', '#^update-manifest\.json$#'];

    /** Install target (the panel's base path; tests point it elsewhere). */
    public string $root;

    public function __construct()
    {
        $this->root = base_path();
    }

    /** @return array{type:string, from:?string, to:string, files:int, deleted:int} */
    public function install(UploadedFile $file): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('افزونه zip در PHP هاست فعال نیست (cPanel ← Select PHP Version ← zip).');
        }
        $zip = new ZipArchive;
        if ($zip->open($file->getRealPath()) !== true) {
            throw new RuntimeException('فایل ZIP معتبر نیست.');
        }
        try {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $names[] = (string) $zip->getNameIndex($i);
            }
            $prefix = $this->prefix($names);
            $manifest = null;
            if (($m = $zip->getFromName($prefix.'update-manifest.json')) !== false) {
                $manifest = json_decode($m, true);
                if (! is_array($manifest) || empty($manifest['from'])) {
                    throw new RuntimeException('فایل مشخصات بسته به‌روزرسانی خراب است.');
                }
            }
            $full = in_array($prefix.'vendor/autoload.php', $names, true) && in_array($prefix.'artisan', $names, true);
            if (! $manifest && ! $full) {
                throw new RuntimeException('این فایل بسته پنل نیست (نه بسته کامل است، نه بسته به‌روزرسانی).');
            }
            $current = (string) config('panel.version');
            if ($manifest && $manifest['from'] !== $current) {
                throw new RuntimeException('این بسته برای به‌روزرسانی از نسخه '.$manifest['from'].' است ولی نسخه نصب‌شده '.$current.' است. بسته مخصوص نسخه '.$current.' یا بسته کامل را بارگذاری کنید.');
            }
            $newConfig = $zip->getFromName($prefix.'config/panel.php');
            $to = $newConfig !== false && preg_match("/'version' => '([^']+)'/", $newConfig, $mm) ? $mm[1] : ($manifest['to'] ?? '?');

            // Validate every path before writing anything.
            $entries = [];
            foreach ($names as $n) {
                if ($prefix !== '' && ! str_starts_with($n, $prefix)) {
                    continue;
                }
                $rel = substr($n, strlen($prefix));
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                $this->checkPath($rel);
                if ($this->skipped($rel)) {
                    continue;
                }
                $entries[$n] = $rel;
            }
            if (! $entries) {
                throw new RuntimeException('بسته فایلی برای نصب ندارد.');
            }

            // Extract to a staging folder first, then move into place (a broken ZIP leaves the panel untouched).
            $stage = storage_path('app/update-'.bin2hex(random_bytes(4)));
            File::ensureDirectoryExists($stage);
            try {
                foreach ($entries as $n => $rel) {
                    $data = $zip->getFromName($n);
                    if ($data === false) {
                        throw new RuntimeException('خواندن «'.$rel.'» از ZIP ممکن نشد؛ فایل ناقص آپلود شده است.');
                    }
                    File::ensureDirectoryExists(dirname($stage.'/'.$rel));
                    file_put_contents($stage.'/'.$rel, $data);
                }
                foreach ($entries as $rel) {
                    $dest = $this->root.'/'.$rel;
                    File::ensureDirectoryExists(dirname($dest));
                    if (! @rename($stage.'/'.$rel, $dest) && ! @copy($stage.'/'.$rel, $dest)) {
                        throw new RuntimeException('نوشتن «'.$rel.'» ممکن نشد (دسترسی فایل روی هاست).');
                    }
                    @chmod($dest, 0644);
                }
            } finally {
                File::deleteDirectory($stage);
            }

            $deleted = 0;
            foreach ((array) ($manifest['deleted'] ?? []) as $rel) {
                $rel = (string) $rel;
                $this->checkPath($rel);
                if (! $this->skipped($rel) && is_file($this->root.'/'.$rel)) {
                    @unlink($this->root.'/'.$rel);
                    $deleted++;
                }
            }
        } finally {
            $zip->close();
        }

        foreach (glob($this->root.'/bootstrap/cache/{config,routes-v7,events}.php', GLOB_BRACE) ?: [] as $f) {
            @unlink($f);   // stale caches would hide the new code
        }
        foreach (glob(storage_path('framework/views/*.php')) ?: [] as $f) {
            @unlink($f);   // compiled templates only (keep .gitignore)
        }
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return ['type' => $manifest ? 'update' : 'full', 'from' => $current, 'to' => $to, 'files' => count($entries), 'deleted' => $deleted];
    }

    /** Release ZIPs have files at the root; tolerate one wrapping folder (e.g. "vpn-panel/"). */
    private function prefix(array $names): string
    {
        foreach (['', 'vpn-panel/'] as $p) {
            if (in_array($p.'config/panel.php', $names, true) || in_array($p.'update-manifest.json', $names, true)) {
                return $p;
            }
        }
        $first = explode('/', $names[0] ?? '')[0] ?? '';

        return $first !== '' && in_array($first.'/config/panel.php', $names, true) ? $first.'/' : '';
    }

    private function checkPath(string $rel): void
    {
        if ($rel === '' || str_starts_with($rel, '/') || str_contains($rel, '\\') || str_contains($rel, "\0") || preg_match('#(^|/)\.\.(/|$)#', $rel) || preg_match('#^[A-Za-z]:#', $rel)) {
            throw new RuntimeException('مسیر نامعتبر در بسته: '.mb_substr($rel, 0, 80));
        }
    }

    private function skipped(string $rel): bool
    {
        foreach (self::SKIP as $re) {
            if (preg_match($re, $rel)) {
                return true;
            }
        }

        return false;
    }
}
