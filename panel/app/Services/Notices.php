<?php

namespace App\Services;

use App\Models\PanelNotice;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * In-panel notifications for staff with system.view: one open problem per key ("router:3", "ibs");
 * raising an open key again does nothing, resolving it closes it and posts a "back up" notice.
 */
class Notices
{
    private ?bool $ready = null;

    private function ready(): bool
    {
        try {
            return $this->ready ??= Schema::hasTable('panel_notices');
        } catch (Throwable) {
            return false;
        }
    }

    /** @return bool true when a new problem was opened */
    public function raise(string $key, string $title, ?string $body = null, ?string $url = null): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $open = PanelNotice::query()->where('key', $key)->where('level', 'danger')->whereNull('resolved_at')->first();
        if ($open) {
            if ($body !== null && $open->body !== $body) {
                $open->forceFill(['body' => mb_substr($body, 0, 1000)])->save();   // keep the latest error, no new notice
            }

            return false;
        }
        PanelNotice::query()->create(['key' => $key, 'level' => 'danger', 'title' => $title, 'body' => $body !== null ? mb_substr($body, 0, 1000) : null, 'url' => $url]);

        return true;
    }

    /** @return bool true when an open problem was closed */
    public function resolve(string $key, string $title, ?string $url = null): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $n = PanelNotice::query()->where('key', $key)->where('level', 'danger')->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
        if ($n === 0) {
            return false;
        }
        PanelNotice::query()->create(['key' => $key, 'level' => 'success', 'title' => $title, 'url' => $url]);

        return true;
    }

    /** @return Collection<int, PanelNotice> */
    public function openProblems(): Collection
    {
        return $this->ready() ? PanelNotice::query()->where('level', 'danger')->whereNull('resolved_at')->orderBy('created_at')->get() : collect();
    }

    public function unreadCount(User $user): int
    {
        if (! $this->ready()) {
            return 0;
        }

        return PanelNotice::query()->when($user->notices_seen_at, fn ($q, $t) => $q->where('created_at', '>', $t))->count();
    }

    public function prune(): void
    {
        if ($this->ready()) {
            PanelNotice::query()->where('created_at', '<', now()->subDays(60))->where(fn ($q) => $q->whereNotNull('resolved_at')->orWhere('level', '!=', 'danger'))->delete();
        }
    }
}
