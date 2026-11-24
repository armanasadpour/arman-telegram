<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Key/value settings store. Values marked as secret are encrypted with APP_KEY
 * at rest and are never rendered back into forms.
 */
class Settings
{
    /** @var array<string, Setting>|null */
    private ?array $cache = null;

    /** Defaults for known keys (used when a key was never saved). */
    public const DEFAULTS = [
        'general.site_name' => 'پنل فروش VPN',
        'general.support_contact' => '',
        'general.display_timezone' => 'Asia/Tehran',
    ];

    public function get(string $key, ?string $default = null): ?string
    {
        $row = $this->all()[$key] ?? null;
        if ($row === null) {
            return $default ?? (self::DEFAULTS[$key] ?? null);
        }
        if ($row->is_encrypted && $row->value !== null) {
            try {
                return Crypt::decryptString($row->value);
            } catch (Throwable) {
                return null; // APP_KEY changed: treat as unset rather than crash
            }
        }

        return $row->value;
    }

    public function has(string $key): bool
    {
        return isset($this->all()[$key]);
    }

    public function set(string $key, ?string $value, bool $encrypt = false): void
    {
        $stored = ($encrypt && $value !== null) ? Crypt::encryptString($value) : $value;
        $row = Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $stored, 'is_encrypted' => $encrypt, 'updated_by' => Auth::id()],
        );
        if ($this->cache !== null) {
            $this->cache[$key] = $row;
        }
    }

    /** @return array<string, Setting> */
    private function all(): array
    {
        if ($this->cache === null) {
            try {
                $this->cache = Setting::query()->get()->keyBy('key')->all();
            } catch (Throwable) {
                $this->cache = []; // not installed yet
            }
        }

        return $this->cache;
    }
}
