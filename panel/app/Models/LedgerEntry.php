<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable ledger row. Never updated or deleted; corrections are new reversing rows.
 *
 * @property string $type manual_credit|manual_debit|topup|purchase|refund|reversal
 * @property int $amount signed rial
 * @property int $balance_after
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    public const TYPES = [
        'manual_credit' => 'شارژ دستی', 'manual_debit' => 'کسر دستی', 'topup' => 'شارژ آنلاین',
        'purchase' => 'خرید', 'refund' => 'بازگشت وجه', 'reversal' => 'اصلاح (معکوس)',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
