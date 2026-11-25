<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A MikroTik RouterOS device the panel manages (L2TP / WireGuard). Password is encrypted and never rendered. */
class Router extends Model
{
    public const TRANSPORTS = [
        'rest' => ['REST API روی HTTPS (پیشنهادی)', 443],
        'api-ssl' => ['API رمزنگاری‌شده (api-ssl)', 8729],
        'api' => ['API ساده (بدون رمزنگاری — فقط شبکه خصوصی)', 8728],
    ];

    protected $fillable = ['name', 'host', 'transport', 'port', 'username', 'password', 'cert_pin', 'is_active', 'l2tp_enabled', 'wireguard_enabled',
        'info', 'inventory', 'last_ok_at', 'last_error_at', 'last_error', 'last_latency_ms', 'health'];

    protected $hidden = ['password'];

    protected $attributes = ['transport' => 'rest', 'port' => 443, 'is_active' => true, 'l2tp_enabled' => false, 'wireguard_enabled' => false];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'is_active' => 'boolean', 'l2tp_enabled' => 'boolean', 'wireguard_enabled' => 'boolean',
            'info' => 'array', 'inventory' => 'array', 'last_ok_at' => 'datetime', 'last_error_at' => 'datetime', 'port' => 'integer'];
    }

    public function needsPin(): bool
    {
        return $this->transport !== 'api';
    }

    public function transportLabel(): string
    {
        return self::TRANSPORTS[$this->transport][0] ?? $this->transport;
    }

    public function isUp(): bool
    {
        return $this->last_ok_at !== null && ($this->last_error_at === null || $this->last_ok_at->gte($this->last_error_at));
    }
}
