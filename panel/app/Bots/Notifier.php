<?php

namespace App\Bots;

use App\Models\BotOutbox;
use App\Models\BotUser;
use App\Models\ResellerSale;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queues messages in bot_outbox (sent by the bots:send cron). Never throws: a notification problem
 * must not break the business operation that triggered it.
 */
class Notifier
{
    public function toUser(int $userId, string $text): int
    {
        return $this->queue(BotUser::query()->where('user_id', $userId)->where('is_blocked', false), $text);
    }

    /** Every linked panel user that holds $permission (staff only; resellers never hold admin permissions). */
    public function toStaff(string $permission, string $text): int
    {
        try {
            $ids = User::query()->where('is_active', true)->whereIn('id', BotUser::query()->whereNotNull('user_id')->select('user_id'))
                ->get()->filter(fn (User $u) => ! $u->isReseller() && $u->hasPermission($permission))->modelKeys();
        } catch (Throwable $e) {
            Log::warning('bot notify failed', ['e' => $e->getMessage()]);

            return 0;
        }

        return $ids ? $this->queue(BotUser::query()->whereIn('user_id', $ids)->where('is_blocked', false), $text) : 0;
    }

    /** Called when a reseller sale changes state. */
    public function saleChanged(ResellerSale $sale, ?string $before): void
    {
        if ($sale->status === $before || ! Cache::add('bot.notified.sale.'.$sale->id.'.'.$sale->status, 1, now()->addDays(2))) {
            return;   // one notification per sale and state, however many code paths observe the change
        }
        $label = 'فروش #'.fa((string) $sale->id);
        try {
            $resellerUser = $sale->reseller?->user_id;
            match ($sale->status) {
                'needs_review' => $this->toStaff('resellers.view', '⚠️ '.$label.' همکار «'.$sale->reseller?->name.'» نیازمند رسیدگی است: نتیجه در سرور نامشخص ماند. در پنل بررسی کنید.'),
                'completed' => $before !== null && $resellerUser ? $this->toUser($resellerUser, '✅ '.$label.' انجام شد'.($sale->account ? ': اکانت '.$sale->account->username : '').'.') : null,
                'refunded' => $before !== null && $resellerUser ? $this->toUser($resellerUser, '↩️ '.$label.' انجام نشد و مبلغ '.fa_money($sale->cost_rial).' به کیف پول شما برگشت.') : null,
                default => null,
            };
        } catch (Throwable $e) {
            Log::warning('bot notify failed', ['e' => $e->getMessage()]);
        }
    }

    private function queue($botUsers, string $text): int
    {
        try {
            $n = 0;
            foreach ($botUsers->with('bot')->get() as $bu) {
                if (! $bu->bot?->is_active) {
                    continue;
                }
                BotOutbox::query()->create(['bot_id' => $bu->bot_id, 'chat_id' => $bu->chat_id, 'text' => $text]);
                $n++;
            }

            return $n;
        } catch (Throwable $e) {
            Log::warning('bot notify failed', ['e' => $e->getMessage()]);

            return 0;
        }
    }
}
