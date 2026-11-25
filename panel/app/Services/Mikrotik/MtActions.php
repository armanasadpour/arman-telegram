<?php

namespace App\Services\Mikrotik;

use App\Models\MtAccount;
use App\Models\Package;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;
use App\RouterOs\RouterOsUnreachable;
use App\Services\AuditLogger;
use App\Services\Ibs\Provisioning;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operations on MikroTik accounts. Limits live in the panel, so renewals and top-ups always succeed in the
 * database; the router is then brought in line (enable/disable). If the router is unreachable at that moment,
 * the accounting cron applies the state on its next successful run.
 */
class MtActions
{
    public function __construct(private RouterConnector $ros, private AuditLogger $audit, private MtProvisioning $prov) {}

    // ---------------------------------------------------------------- limits

    public function renew(MtAccount $a, int $days, string $reason, ?int $actorId = null): MtAccount
    {
        if ($days < 1 || $days > 3650) {
            throw new RuntimeException('تعداد روز نامعتبر است.');
        }
        if ($a->duration_days === null && $a->expires_at === null) {
            throw new RuntimeException('این اکانت محدودیت زمانی ندارد.');
        }
        $before = ['expires_at' => $a->expires_at?->toIso8601String(), 'duration_days' => $a->duration_days];
        DB::transaction(function () use ($a, $days) {
            $a->refresh();
            if ($a->expires_at === null && $a->first_used_at === null) {
                $a->duration_days = (int) $a->duration_days + $days;        // not started: the period just gets longer
            } elseif ($a->expires_at === null || $a->expires_at->isPast()) {
                $a->expires_at = now()->addDays($days);
                $a->duration_days = $days;
            } else {
                $a->expires_at = $a->expires_at->copy()->addDays($days);
                $a->duration_days = (int) ceil(($a->expires_at->getTimestamp() - now()->getTimestamp()) / 86400);
            }
            $this->recomputeStatus($a);
            $a->alerted_at = null;
            $a->save();
        });
        $this->audit->log('mt_accounts.renew', $a, 'تمدید «'.$a->username.'» به مدت '.fa($days).' روز — '.$reason,
            ['expires_at' => [$before['expires_at'], $a->expires_at?->toIso8601String()], 'duration_days' => [$before['duration_days'], $a->duration_days]]);

        return $this->syncState($a);
    }

    public function addVolume(MtAccount $a, float $gb, string $reason, ?int $actorId = null): MtAccount
    {
        if ($gb <= 0) {
            throw new RuntimeException('حجم نامعتبر است.');
        }
        if (! $a->isVolumeLimited()) {
            throw new RuntimeException('این اکانت حجم نامحدود دارد.');
        }
        $old = $a->volume_limit_bytes;
        DB::transaction(function () use ($a, $gb) {
            $a->refresh();
            // A depleted account starts the new volume from what it actually used, not from the overshoot.
            $base = max((int) $a->volume_limit_bytes, (int) $a->used_bytes);
            $a->volume_limit_bytes = $base + (int) round($gb * 1073741824);
            $this->recomputeStatus($a);
            $a->alerted_at = null;
            $a->save();
        });
        $this->audit->log('mt_accounts.volume', $a, 'افزایش حجم «'.$a->username.'» به میزان '.fa($gb).' گیگابایت — '.$reason,
            ['volume_limit_bytes' => [$old, $a->volume_limit_bytes]]);

        return $this->syncState($a);
    }

    /** Admin edit of limits: absolute values. */
    public function setLimits(MtAccount $a, ?int $daysLeft, ?float $volumeGb, ?int $multiLogin, string $reason): MtAccount
    {
        $before = $a->only(['expires_at', 'duration_days', 'volume_limit_bytes', 'multi_login']);
        DB::transaction(function () use ($a, $daysLeft, $volumeGb, $multiLogin) {
            $a->refresh();
            if ($daysLeft !== null) {
                if ($a->expires_at || $a->first_used_at) {
                    $a->expires_at = $daysLeft > 0 ? now()->addDays($daysLeft) : now();
                }
                $a->duration_days = max(1, $daysLeft);
            }
            $a->volume_limit_bytes = $volumeGb !== null && $volumeGb > 0 ? (int) round($volumeGb * 1073741824) : null;
            if (! $a->isWireguard()) {
                $a->multi_login = $multiLogin ?: null;
            }
            $this->recomputeStatus($a);
            $a->save();
        });
        $this->audit->log('mt_accounts.limits', $a, 'ویرایش محدودیت‌های «'.$a->username.'»', AuditLogger::diff(
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $before),
            array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v, $a->only(array_keys($before)))), $reason);

        return $this->syncState($a);
    }

    public function resetUsage(MtAccount $a, string $reason): MtAccount
    {
        $old = $a->used_bytes;
        $a->forceFill(['used_bytes' => 0, 'upload_bytes' => 0, 'download_bytes' => 0, 'alerted_at' => null]);
        $this->recomputeStatus($a);
        $a->save();
        $this->audit->log('mt_accounts.reset_usage', $a, 'صفر کردن مصرف «'.$a->username.'»', ['used_bytes' => [$old, 0]], $reason);

        return $this->syncState($a);
    }

    public function applyPackage(Package $p, MtAccount $a, string $reason): MtAccount
    {
        if ($why = self::applicability($p, $a)) {
            throw new RuntimeException($why);
        }
        if ($p->duration_days) {
            $a = $this->renew($a, (int) $p->duration_days, 'بسته «'.$p->name.'» — '.$reason);
        }
        if ($p->hasVolume()) {
            $a = $this->addVolume($a, (float) $p->volume_gb, 'بسته «'.$p->name.'» — '.$reason);
        }

        return $a;
    }

    public static function applicability(Package $p, MtAccount $a): ?string
    {
        if ($p->protocol !== 'mikrotik' || ! in_array($p->type, ['renewal', 'topup'], true)) {
            return 'این بسته برای تمدید یا افزایش حجم اکانت‌های L2TP/WireGuard نیست.';
        }
        if (! $p->is_active) {
            return 'این بسته غیرفعال است.';
        }
        if ($a->trashed() || in_array($a->status, ['pending', 'failed'], true)) {
            return 'این اکانت قابل تمدید نیست.';
        }
        if ($p->hasVolume() && ! $a->isVolumeLimited()) {
            return 'این بسته حجم دارد ولی اکانت حجم نامحدود است.';
        }

        return null;
    }

    // ---------------------------------------------------------------- enable / disable

    public function disable(MtAccount $a, string $reason): MtAccount
    {
        if (! in_array($a->status, ['active', 'expired', 'depleted'], true)) {
            throw new RuntimeException('این اکانت در وضعیت قابل غیرفعال‌سازی نیست.');
        }
        $a->forceFill(['status' => 'disabled'])->save();
        $this->audit->log('mt_accounts.disable', $a, 'غیرفعال کردن «'.$a->username.'»', [], $reason);

        return $this->syncState($a);
    }

    public function enable(MtAccount $a, string $reason): MtAccount
    {
        if ($a->status !== 'disabled') {
            throw new RuntimeException('فقط اکانت غیرفعال‌شده دستی را می‌توان فعال کرد؛ برای منقضی یا حجم‌تمام از تمدید/افزایش حجم استفاده کنید.');
        }
        $a->status = 'active';
        $this->recomputeStatus($a);
        $a->save();
        $this->audit->log('mt_accounts.enable', $a, 'فعال کردن «'.$a->username.'»', [], $reason);

        return $this->syncState($a);
    }

    /** active ⇄ expired ⇄ depleted follow the limits; disabled / pending / failed are left alone. */
    public function recomputeStatus(MtAccount $a): void
    {
        if (! in_array($a->status, ['active', 'expired', 'depleted'], true)) {
            return;
        }
        $a->status = $a->isExpired() ? 'expired' : ($a->isDepleted() ? 'depleted' : 'active');
    }

    /** Make the router match the panel (enabled iff status active). Router errors are recorded, not thrown. */
    public function syncState(MtAccount $a, bool $throw = false): MtAccount
    {
        if (! $a->ros_id || $a->trashed()) {
            return $a;
        }
        $want = ! $a->shouldBeEnabled();
        if ($want === $a->router_disabled) {
            return $a;
        }
        try {
            $this->ros->set($a->router, $this->menu($a), $a->ros_id, ['disabled' => $want ? 'yes' : 'no']);
            if ($want) {
                $this->kick($a);
            }
            $a->forceFill(['router_disabled' => $want, 'error' => null])->save();
        } catch (RouterOsException $e) {
            $a->forceFill(['error' => mb_substr(($want ? 'قطع' : 'وصل').' روی روتر انجام نشد؛ در همگام‌سازی بعدی تکرار می‌شود. '.$e->getMessage(), 0, 500)])->save();
            if ($throw) {
                throw $e;
            }
        }

        return $a;
    }

    /** Drop live L2TP sessions (a disabled secret does not end an existing session). */
    public function kick(MtAccount $a, ?int $keep = 0): int
    {
        if ($a->isWireguard()) {
            return 0;
        }
        $rows = $this->ros->get($a->router, 'ppp/active', ['name' => $a->username]);
        usort($rows, fn ($x, $y) => (RosValues::seconds($y['uptime'] ?? '') ?? 0) <=> (RosValues::seconds($x['uptime'] ?? '') ?? 0));   // oldest first
        $n = 0;
        foreach (array_slice($rows, (int) $keep) as $row) {
            $this->ros->remove($a->router, 'ppp/active', (string) $row['.id']);
            $n++;
        }

        return $n;
    }

    // ---------------------------------------------------------------- credentials

    public function changePassword(MtAccount $a, ?string $password, string $reason): string
    {
        if ($a->isWireguard()) {
            throw new RuntimeException('اکانت WireGuard رمز ندارد؛ از «کلید جدید» استفاده کنید.');
        }
        $password = $password !== null && $password !== '' ? $password : Provisioning::generatePassword();
        if (mb_strlen($password) > 64 || preg_match('/[\x00-\x1F]/', $password)) {
            throw new RuntimeException('رمز نامعتبر است.');
        }
        $this->needRouterRow($a);
        $this->ros->set($a->router, 'ppp/secret', $a->ros_id, ['password' => $password]);
        $a->forceFill(['password' => $password])->save();
        $this->audit->log('mt_accounts.password', $a, 'تغییر رمز «'.$a->username.'»', ['password' => ['***', '***']], $reason);

        return $password;
    }

    public function regenerateKeys(MtAccount $a, string $reason): MtAccount
    {
        if (! $a->isWireguard()) {
            throw new RuntimeException('فقط اکانت WireGuard کلید دارد.');
        }
        $this->needRouterRow($a);
        $k = WgKeys::pair();
        $psk = WgKeys::preshared();
        $this->ros->set($a->router, 'interface/wireguard/peers', $a->ros_id, ['public-key' => $k['public'], 'preshared-key' => $psk]);
        $a->forceFill(['wg_private_key' => $k['private'], 'wg_public_key' => $k['public'], 'wg_preshared_key' => $psk])->save();
        $this->audit->log('mt_accounts.keys', $a, 'ساخت کلید جدید WireGuard برای «'.$a->username.'» (فایل قبلی دیگر کار نمی‌کند)', [], $reason);

        return $a;
    }

    // ---------------------------------------------------------------- delete

    /** Remove from the router, then from the panel. $panelOnly skips the router (when it is gone for good). */
    public function delete(MtAccount $a, string $reason, bool $panelOnly = false): void
    {
        if (! $panelOnly && $a->ros_id) {
            try {
                $this->ros->remove($a->router, $this->menu($a), $a->ros_id);
            } catch (RouterOsUnreachable $e) {
                throw new RuntimeException('روتر در دسترس نیست؛ حذف انجام نشد. '.$e->getMessage());
            } catch (RouterOsException) {
                // already gone on the router
            }
            if ($a->isWireguard()) {
                try {
                    foreach ($this->ros->get($a->router, 'queue/simple', ['name' => 'panel-'.$a->username]) as $q) {
                        $this->ros->remove($a->router, 'queue/simple', (string) $q['.id']);
                    }
                } catch (RouterOsException) {
                }
            } else {
                try {
                    $this->kick($a);
                } catch (RouterOsException) {
                }
            }
        }
        DB::transaction(function () use ($a) {
            // Free the name and the address for reuse; keep the row (soft-deleted) for history and sales.
            $a->forceFill(['username' => mb_substr($a->username, 0, 40).'~'.$a->id, 'wg_address' => null, 'is_online' => false, 'online_count' => 0])->save();
            $a->delete();
        });
        $this->audit->log('mt_accounts.delete', $a, 'حذف اکانت «'.preg_replace('/~\d+$/', '', $a->username).'»'.($panelOnly ? ' (فقط از پنل)' : ''), [], $reason);
    }

    public function retryCreate(MtAccount $a): MtAccount
    {
        if ($a->status === 'failed') {
            $a->forceFill(['status' => 'pending'])->save();
        }

        return $this->prov->push($a);
    }

    private function needRouterRow(MtAccount $a): void
    {
        if (! $a->ros_id || $a->trashed()) {
            throw new RuntimeException('این اکانت روی روتر ساخته نشده است.');
        }
    }

    public function menu(MtAccount $a): string
    {
        return $a->isWireguard() ? 'interface/wireguard/peers' : 'ppp/secret';
    }
}
