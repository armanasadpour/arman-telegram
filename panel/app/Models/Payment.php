<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One payment attempt. A successful payment only credits the payer's wallet (ledger row "pay:{id}");
 * whatever it was for (an order) is then bought from that wallet.
 */
class Payment extends Model
{
    public const METHODS = [
        'zarinpal' => 'زرین‌پال', 'sep' => 'بانک سامان (سپ)', 'mellat' => 'به‌پرداخت ملت', 'sadad' => 'سداد (بانک ملی)',
        'card' => 'کارت به کارت', 'usdt' => 'تتر (USDT)',
    ];

    public const ONLINE = ['zarinpal', 'sep', 'mellat', 'sadad'];

    public const STATUS = [
        'pending' => ['ایجاد شده', 'primary'], 'redirected' => ['در درگاه', 'primary'], 'review' => ['در انتظار بررسی', 'warning'],
        'paid' => ['پرداخت شده', 'success'], 'failed' => ['ناموفق', 'danger'], 'rejected' => ['رد شده', 'danger'], 'expired' => ['منقضی', 'danger'],
    ];

    public const NETWORKS = ['trc20' => 'TRC20 (ترون)', 'erc20' => 'ERC20 (اتریوم)', 'bep20' => 'BEP20 (BSC)'];

    protected $fillable = ['uuid', 'method', 'wallet_id', 'amount_rial', 'status', 'purpose', 'gateway_token', 'gateway_ref', 'card_pan',
        'usdt_network', 'usdt_amount', 'tx_hash', 'payer_note', 'receipt_path', 'error', 'meta', 'ledger_entry_id', 'reviewed_by',
        'review_reason', 'created_by', 'bot_user_id', 'paid_at'];

    protected $attributes = ['status' => 'pending', 'purpose' => 'topup'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'paid_at' => 'datetime', 'amount_rial' => 'integer', 'usdt_amount' => 'decimal:6'];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function order(): HasOne
    {
        return $this->hasOne(CustomerOrder::class);
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function isOnline(): bool
    {
        return in_array($this->method, self::ONLINE, true);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['paid', 'failed', 'rejected', 'expired'], true);
    }

    /** Who pays: "همکار R1001" / "مشتری ربات …". */
    public function payerLabel(): string
    {
        $w = $this->wallet;
        if ($w?->owner_type === 'reseller') {
            $r = Reseller::query()->find($w->owner_id);

            return 'همکار '.($r ? $r->name.' ('.$r->code.')' : '#'.$w->owner_id);
        }
        if ($w?->owner_type === 'customer') {
            $bu = BotUser::query()->with('bot')->find($w->owner_id);

            return 'مشتری '.($bu ? ($bu->bot?->label().' — '.($bu->first_name ?: $bu->chat_id)) : '#'.$w->owner_id);
        }

        return '—';
    }
}
