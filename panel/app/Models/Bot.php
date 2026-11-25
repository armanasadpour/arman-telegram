<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One row per messenger platform. The token is encrypted at rest and never rendered.
 *
 * @property string $platform telegram|bale|rubika
 * @property ?string $token
 */
class Bot extends Model
{
    public const PLATFORMS = ['telegram' => 'تلگرام', 'bale' => 'بله', 'rubika' => 'روبیکا'];

    public const RELAYS = ['direct' => 'مستقیم', 'cloudflare' => 'Cloudflare Worker', 'gas' => 'Google Apps Script'];

    protected $fillable = ['platform', 'token', 'hook_secret', 'is_active', 'relay_mode', 'relay_url', 'bot_username', 'webhook_set_at', 'last_update_at', 'last_error_at', 'last_error'];

    protected $hidden = ['token', 'hook_secret'];

    protected $attributes = ['is_active' => false, 'relay_mode' => 'direct'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'is_active' => 'boolean', 'webhook_set_at' => 'datetime', 'last_update_at' => 'datetime', 'last_error_at' => 'datetime'];
    }

    public static function for(string $platform): self
    {
        return static::query()->firstOrCreate(['platform' => $platform], ['hook_secret' => Str::random(40)]);
    }

    public function users(): HasMany
    {
        return $this->hasMany(BotUser::class);
    }

    public function label(): string
    {
        return self::PLATFORMS[$this->platform] ?? $this->platform;
    }

    public function hookUrl(): string
    {
        return url('/hook/'.$this->platform.'/'.$this->hook_secret);
    }

    /** Shared secret between the panel and a Telegram relay (derived, so it never needs its own column). */
    public function relaySecret(): string
    {
        return substr(hash_hmac('sha256', 'relay:'.$this->id.':'.$this->hook_secret, (string) config('app.key')), 0, 48);
    }

    public function isConfigured(): bool
    {
        return (string) $this->token !== '';
    }

    public function maskedToken(): ?string
    {
        $t = (string) $this->token;

        return $t === '' ? null : substr($t, 0, 4).'…'.substr($t, -3);
    }
}
