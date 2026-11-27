<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelNotice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function isOpenProblem(): bool
    {
        return $this->level === 'danger' && $this->resolved_at === null;
    }
}
