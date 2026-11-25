<?php

namespace App\Services\Ibs;

use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsUnavailableException;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Settings;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Bulk delete of accounts expired for more than N days. Runs in the background (cron + "continue now"),
 * because there can be thousands. Every account is re-read from the server just before deletion: if it
 * was renewed in the meantime, is online, or the expiry moved, it is skipped. Each deletion is audited.
 */
class ExpiredCleanup
{
    public const MIN_DAYS = 30;

    public function __construct(private IbsConnector $ibs, private AccountSync $sync, private Settings $settings, private AuditLogger $audit) {}

    public function query(Carbon $cutoff): Builder
    {
        return VpnAccount::query()->whereNull('ibs_deleted_at')->whereNotNull('expires_at')->where('expires_at', '<', $cutoff)->where('is_online', false);
    }

    public function cutoffFor(int $days): Carbon
    {
        return now()->subDays(max(self::MIN_DAYS, $days));
    }

    public function state(): ?array
    {
        $s = $this->settings->get('cleanup.state');

        return $s ? json_decode($s, true) : null;
    }

    public function start(int $days, string $reason, int $actorId): array
    {
        if (($this->state()['running'] ?? false)) {
            throw new RuntimeException('یک پاک‌سازی در حال انجام است.');
        }
        $cutoff = $this->cutoffFor($days);
        $st = ['running' => true, 'days' => max(self::MIN_DAYS, $days), 'cutoff' => $cutoff->toIso8601String(), 'reason' => mb_substr($reason, 0, 200),
            'actor' => $actorId, 'last_id' => 0, 'total' => $this->query($cutoff)->count(), 'deleted' => 0, 'skipped' => 0, 'failed' => 0,
            'started_at' => now()->toIso8601String()];
        $this->save($st);
        $this->audit->log('accounts.cleanup_started', null, 'شروع حذف گروهی اکانت‌های منقضی بیش از '.$st['days'].' روز ('.$st['total'].' اکانت)', [], $reason);

        return $st;
    }

    public function cancel(int $actorId): void
    {
        $st = $this->state();
        if ($st && $st['running']) {
            $st['running'] = false;
            $st['cancelled_at'] = now()->toIso8601String();
            $this->save($st);
            $this->audit->log('accounts.cleanup_cancelled', null, 'توقف حذف گروهی ('.$st['deleted'].' حذف شده)');
        }
    }

    /** Process accounts until $deadline (microtime). */
    public function step(float $deadline): ?array
    {
        $st = $this->state();
        if (! $st || ! $st['running']) {
            return $st;
        }
        $cutoff = Carbon::parse($st['cutoff']);
        while (microtime(true) < $deadline) {
            $batch = $this->query($cutoff)->where('id', '>', $st['last_id'])->orderBy('id')->limit(20)->get();
            if ($batch->isEmpty()) {
                $st['running'] = false;
                $st['finished_at'] = now()->toIso8601String();
                $this->audit->log('accounts.cleanup_finished', null, 'پایان حذف گروهی: '.$st['deleted'].' حذف، '.$st['skipped'].' رد، '.$st['failed'].' خطا', [], $st['reason']);
                break;
            }
            foreach ($batch as $a) {
                if (microtime(true) >= $deadline) {
                    break 2;
                }
                try {
                    // The server is the truth: gone, online right now, or renewed since the panel last looked?
                    $info = $this->liveInfo($a);
                    if ($info !== null) {
                        $a = $this->sync->apply($info) ?? $a;
                    }
                    if ($info === null) {
                        $st['skipped']++;
                    } elseif (! empty($info['online_status']) || ! $a->expires_at || $a->expires_at->gte($cutoff)) {
                        $st['skipped']++;
                    } else {
                        $this->ibs->deleteUser($a->ibs_user_id, mb_substr('panel cleanup: '.$st['reason'], 0, 200));
                        $a->forceFill(['ibs_deleted_at' => now(), 'is_online' => false])->save();
                        $this->audit->log('accounts.delete', $a, 'حذف گروهی اکانت منقضی «'.$a->username.'» (انقضا '.jdate($a->expires_at, 'Y/m/d').')', ['ibs_user_id' => [$a->ibs_user_id, null]], $st['reason']);
                        $a->delete();
                        $st['deleted']++;
                    }
                } catch (IbsUnavailableException) {
                    $this->save($st);   // server down: stop here, the next run continues from the same account

                    return $st;
                } catch (IbsException $e) {
                    $st['failed']++;
                    $st['last_error'] = mb_substr($e->getMessage(), 0, 200);
                }
                $st['last_id'] = $a->id;
                $this->save($st);
            }
        }
        $this->save($st);

        return $st;
    }

    /** Live getUserInfo entry, or null when the user no longer exists on the server (then marked deleted). */
    private function liveInfo(VpnAccount $a): ?array
    {
        try {
            $info = $this->ibs->usersByIds([$a->ibs_user_id])[(string) $a->ibs_user_id] ?? null;
        } catch (IbsException $e) {
            if ($e->ibsCode !== 'USERID_DOESNT_EXISTS') {
                throw $e;
            }
            $info = null;
        }
        if ($info === null) {
            $a->forceFill(['ibs_deleted_at' => now(), 'is_online' => false, 'last_synced_at' => now()])->save();
        }

        return $info ? (array) $info : null;
    }

    private function save(array $st): void
    {
        $this->settings->set('cleanup.state', json_encode($st));
    }
}
