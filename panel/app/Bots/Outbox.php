<?php

namespace App\Bots;

use App\Models\BotOutbox;
use Throwable;

/** Sends queued notifications (cron, every minute). Retries with back-off, then gives up. */
class Outbox
{
    private const BACKOFF_MIN = [1, 5, 15, 60, 180];

    public function __construct(private Platforms $platforms) {}

    public function flush(int $limit = 50): array
    {
        $sent = $failed = 0;
        $rows = BotOutbox::query()->with('bot')->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            if (! $row->bot?->is_active || ! $row->bot->isConfigured()) {
                continue;
            }
            try {
                $this->platforms->for($row->bot)->send($row->chat_id, $row->text);
                $row->forceFill(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1])->save();
                $sent++;
            } catch (Throwable $e) {
                $n = $row->attempts + 1;
                $row->forceFill([
                    'attempts' => $n, 'error' => mb_substr($e->getMessage(), 0, 500),
                    'status' => $n > count(self::BACKOFF_MIN) ? 'failed' : 'pending',
                    'next_attempt_at' => now()->addMinutes(self::BACKOFF_MIN[min($n, count(self::BACKOFF_MIN)) - 1]),
                ])->save();
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
