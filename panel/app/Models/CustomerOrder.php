<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrder extends Model
{
    public const STATUS = [
        'awaiting_payment' => ['در انتظار پرداخت', 'primary'], 'pending' => ['در حال انجام', 'primary'], 'completed' => ['انجام شد', 'success'],
        'refunded' => ['ناموفق — مبلغ در کیف پول مشتری', 'warning'], 'needs_review' => ['نیازمند رسیدگی', 'danger'], 'cancelled' => ['لغو شده', 'danger'],
    ];

    protected $fillable = ['order_key', 'bot_user_id', 'package_id', 'type', 'price_snapshot', 'price_rial', 'payment_id', 'vpn_account_id',
        'provisioning_job_id', 'debit_entry_id', 'refund_entry_id', 'status', 'error', 'secret', 'mt_account_id'];

    protected $hidden = ['secret'];

    protected $attributes = ['status' => 'awaiting_payment'];

    protected function casts(): array
    {
        return ['price_snapshot' => 'array', 'price_rial' => 'integer', 'secret' => 'encrypted'];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VpnAccount::class, 'vpn_account_id');
    }

    public function mtAccount(): BelongsTo
    {
        return $this->belongsTo(MtAccount::class, 'mt_account_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProvisioningJob::class, 'provisioning_job_id');
    }
}
