<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A MikroTik account group: which router/protocol, the PPP profile or WireGuard interface, and defaults. */
class MtGroup extends Model
{
    public const PROTOCOLS = ['l2tp' => 'L2TP/IPsec', 'wireguard' => 'WireGuard'];

    protected $guarded = ['id'];

    protected $hidden = ['ipsec_psk'];

    protected $attributes = ['start_on_first_use' => true, 'is_active' => true, 'wg_keepalive' => 25, 'wg_allowed_ips' => '0.0.0.0/0, ::/0'];

    protected function casts(): array
    {
        return ['ipsec_psk' => 'encrypted', 'start_on_first_use' => 'boolean', 'is_active' => 'boolean', 'multi_login' => 'integer',
            'default_days' => 'integer', 'wg_listen_port' => 'integer', 'wg_mtu' => 'integer', 'wg_keepalive' => 'integer'];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(MtAccount::class);
    }

    public function isWireguard(): bool
    {
        return $this->protocol === 'wireguard';
    }

    public function protocolLabel(): string
    {
        return self::PROTOCOLS[$this->protocol] ?? $this->protocol;
    }

    public function endpoint(): string
    {
        return $this->endpoint_host ?: (string) $this->router?->host;
    }

    public function label(): string
    {
        return $this->name.' — '.$this->protocolLabel().' ('.$this->router?->name.')';
    }
}
