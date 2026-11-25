<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BotUser extends Model
{
    protected $fillable = ['bot_id', 'chat_id', 'first_name', 'handle', 'user_id', 'state', 'state_data', 'failed_logins', 'login_blocked_until', 'is_blocked', 'last_seen_at'];

    protected $attributes = ['failed_logins' => 0, 'is_blocked' => false];

    protected function casts(): array
    {
        return ['state_data' => 'array', 'is_blocked' => 'boolean', 'login_blocked_until' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(VpnAccount::class, 'bot_account_links')->withPivot('created_at');
    }

    public function reseller(): ?Reseller
    {
        return $this->user?->isReseller() ? $this->user->reseller : null;
    }

    public function setState(?string $state, array $data = []): void
    {
        $this->forceFill(['state' => $state, 'state_data' => $state ? $data : null])->save();
    }
}
