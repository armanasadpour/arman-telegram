<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A client config file (OpenVPN profile...) with a secret direct link. Content is stored encrypted. */
class ConfigFile extends Model
{
    protected $fillable = ['name', 'protocol', 'extension', 'content', 'created_by'];

    protected $hidden = ['content', 'token'];

    protected function casts(): array
    {
        return ['content' => 'encrypted', 'last_download_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $f) {
            $f->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fileName(): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->name) ?: 'config';

        return trim($base, '-.').'.'.$this->extension;
    }

    public function publicUrl(): string
    {
        return route('config-files.public', $this->token);
    }

    public function sizeLabel(): string
    {
        return fa(number_format(strlen((string) $this->content) / 1024, 1)).' KB';
    }
}
