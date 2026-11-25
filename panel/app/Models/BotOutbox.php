<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotOutbox extends Model
{
    protected $table = 'bot_outbox';

    protected $fillable = ['bot_id', 'chat_id', 'text', 'status', 'attempts', 'next_attempt_at', 'error', 'sent_at'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function bot(): BelongsTo
    {
        return $this->belongsTo(Bot::class);
    }
}
