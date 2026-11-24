<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $job_key
 * @property string $status pending|raw_created|completed|compensated|needs_review|failed
 * @property array $spec
 */
class ProvisioningJob extends Model
{
    public const OPEN = ['pending', 'raw_created', 'needs_review'];

    protected $fillable = ['job_key', 'type', 'username', 'status', 'ibs_user_id', 'spec', 'password_encrypted', 'attempts', 'last_error', 'vpn_account_id', 'created_by', 'finished_at'];

    protected $hidden = ['password_encrypted'];

    protected function casts(): array
    {
        return ['spec' => 'array', 'finished_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(VpnAccount::class, 'vpn_account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return [
            'pending' => 'در انتظار', 'raw_created' => 'نیمه‌کاره', 'completed' => 'تکمیل‌شده',
            'compensated' => 'لغو و پاک‌سازی شد', 'needs_review' => 'نیازمند رسیدگی', 'failed' => 'ناموفق',
        ][$this->status] ?? $this->status;
    }
}
