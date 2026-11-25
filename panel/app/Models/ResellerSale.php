<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $status pending|completed|refunded|needs_review|failed */
class ResellerSale extends Model
{
    public const STATUS = [
        'pending' => ['در حال انجام', 'primary'], 'completed' => ['موفق', 'success'], 'refunded' => ['ناموفق — وجه برگشت داده شد', 'warning'],
        'needs_review' => ['نیازمند رسیدگی', 'danger'], 'failed' => ['ناموفق', 'danger'],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['price_snapshot' => 'array', 'cost_rial' => 'integer', 'sale_price_rial' => 'integer', 'profit_rial' => 'integer'];
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class)->withTrashed();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VpnAccount::class, 'vpn_account_id')->withTrashed();
    }

    public function mtAccount(): BelongsTo
    {
        return $this->belongsTo(MtAccount::class, 'mt_account_id')->withTrashed();
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(ProvisioningJob::class, 'provisioning_job_id');
    }
}
