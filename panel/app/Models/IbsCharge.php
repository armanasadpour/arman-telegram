<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $name
 * @property string $cpk credit per kilobyte
 */
class IbsCharge extends Model
{
    protected $fillable = ['name', 'cpk', 'cpm', 'rules', 'present', 'synced_at'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'present' => 'boolean', 'synced_at' => 'datetime'];
    }

    /** Volume-limited when at least one rule charges per kilobyte. */
    public function isVolume(): bool
    {
        return (float) $this->cpk > 0;
    }

    /** Bytes of traffic that one unit of IBSng credit buys under this charge. */
    public function bytesPerCredit(): ?float
    {
        return $this->isVolume() ? 1024 / (float) $this->cpk : null;
    }
}
