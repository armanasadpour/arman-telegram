<?php

namespace App\Services\Mikrotik;

use App\Models\MtAccount;
use App\Models\MtDevice;
use App\Models\MtGroup;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;
use App\RouterOs\RouterOsUnreachable;
use App\Services\AuditLogger;
use App\Services\Ibs\Provisioning;
use App\Services\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creating an L2TP secret / WireGuard peer, idempotent on create_key:
 *   1. the panel row is written first (status pending, keys and address reserved);
 *   2. the router is told; before adding, the router is searched for a row carrying our comment, so a retry
 *      after a lost reply adopts what was created instead of creating it twice.
 * Outcome: active (done) | pending (router unreachable — cron retries) | failed (router refused — nothing exists).
 */
class MtProvisioning
{
    public const USERNAME_RULE = '/^[a-z0-9][a-z0-9._-]{2,31}$/';

    public const MAX_DEVICES = 10;

    public function __construct(private RouterConnector $ros, private AuditLogger $audit) {}

    /**
     * @param  array{username?:?string, password?:?string, days?:?int, volume_gb?:float|int|string|null, multi_login?:?int,
     *               comment?:?string, package_id?:?int, reseller_id?:?int}  $spec
     */
    public function create(MtGroup $group, string $key, array $spec, ?int $actorId): MtAccount
    {
        if ($existing = MtAccount::withTrashed()->where('create_key', $key)->first()) {
            return $existing->status === 'pending' && ! $existing->trashed() ? $this->push($existing) : $existing;
        }
        $router = $group->router;
        if (! $group->is_active || ! $router || ! $router->is_active) {
            throw new RuntimeException('این گروه یا روتر آن غیرفعال است.');
        }
        if (! ($group->isWireguard() ? $router->wireguard_enabled : $router->l2tp_enabled)) {
            throw new RuntimeException($group->protocolLabel().' روی روتر «'.$router->name.'» فعال نشده است.');
        }
        if ($group->isWireguard() && (! $group->wg_interface || ! $group->wg_network || ! WgKeys::valid((string) $group->wg_server_public_key) || ! $group->wg_listen_port)) {
            throw new RuntimeException('تنظیمات WireGuard گروه کامل نیست (اینترفیس، شبکه، کلید عمومی سرور و پورت). گروه را ویرایش و «خواندن از روتر» را بزنید.');
        }
        if (! $group->isWireguard() && ! $group->ppp_profile) {
            throw new RuntimeException('پروفایل PPP گروه مشخص نشده است.');
        }

        $username = trim(strtolower((string) ($spec['username'] ?? '')));
        if ($username === '') {
            $username = $this->generateUsername($group);
        } elseif (! preg_match(self::USERNAME_RULE, $username)) {
            throw new RuntimeException('نام کاربری باید ۳ تا ۳۲ نویسه از حروف کوچک انگلیسی، عدد، نقطه، خط تیره یا زیرخط باشد.');
        } elseif ($this->taken($group, $username)) {
            throw new RuntimeException('نام کاربری «'.$username.'» روی این روتر قبلاً استفاده شده است.');
        }
        $days = ! empty($spec['days']) ? max(1, (int) $spec['days']) : null;
        $volGb = isset($spec['volume_gb']) && (float) $spec['volume_gb'] > 0 ? (float) $spec['volume_gb'] : null;

        $row = [
            'router_id' => $router->id, 'mt_group_id' => $group->id, 'protocol' => $group->protocol, 'username' => $username, 'create_key' => $key,
            'status' => 'pending', 'duration_days' => $days, 'volume_limit_bytes' => $volGb !== null ? (int) round($volGb * 1073741824) : null,
            // L2TP: simultaneous sessions. WireGuard: number of devices (one key, IP and file each).
            'multi_login' => $group->isWireguard() ? max(1, min(self::MAX_DEVICES, (int) ($spec['multi_login'] ?? 0) ?: (int) ($group->multi_login ?? 1)))
                : (! empty($spec['multi_login']) ? (int) $spec['multi_login'] : null),
            'comment' => isset($spec['comment']) ? mb_substr(trim((string) $spec['comment']), 0, 150) : null,
            'package_id' => $spec['package_id'] ?? null, 'reseller_id' => $spec['reseller_id'] ?? null, 'created_by' => $actorId,
        ];
        if (! $group->start_on_first_use && $days) {
            $row['expires_at'] = now()->addDays($days);
        }
        if ($group->isWireguard()) {
            $keys = WgKeys::pair();
            $row += ['wg_private_key' => $keys['private'], 'wg_public_key' => $keys['public'], 'wg_preshared_key' => WgKeys::preshared()];
        } else {
            $row['password'] = ($spec['password'] ?? null) ?: Provisioning::generatePassword();
        }

        $account = null;
        for ($try = 0; $try < 5 && ! $account; $try++) {
            try {
                $account = DB::transaction(function () use ($row, $group) {
                    if ($group->isWireguard()) {
                        $row['wg_address'] = $this->freeAddress($group);
                    }

                    return MtAccount::query()->create($row);
                });
            } catch (QueryException $e) {
                if ($existing = MtAccount::withTrashed()->where('create_key', $key)->first()) {
                    return $existing;   // a concurrent submit of the same key won
                }
                if ($try === 4 || ! $group->isWireguard()) {
                    throw new RuntimeException('ثبت اکانت ممکن نشد (نام کاربری تکراری یا آدرس IP در حال استفاده). دوباره تلاش کنید.');
                }
            }
        }
        $this->audit->log('mt_accounts.create', $account, 'ساخت اکانت '.$account->protocolLabel().' «'.$username.'» روی روتر «'.$router->name.'»', [
            'group' => [null, $group->name], 'days' => [null, $days], 'volume_gb' => [null, $volGb], 'password' => [null, '***'],
        ]);

        return $this->push($account);
    }

    /** Send a pending account to the router (also used by the retry cron). */
    public function push(MtAccount $a): MtAccount
    {
        if ($a->status !== 'pending') {
            return $a;
        }
        $g = $a->group;
        $r = $a->router;
        try {
            if ($a->isWireguard()) {
                $id = $this->pushPeer($a, $a, $g, $r);
                $a->forceFill(['ros_id' => $id])->save();
                $this->syncDevices($a);
            } else {
                $id = $this->pushSecret($a, $g, $r);
            }
        } catch (RouterOsUnreachable $e) {
            $a->forceFill(['error' => mb_substr('روتر در دسترس نیست؛ ساخت به‌طور خودکار دوباره تلاش می‌شود. '.$e->getMessage(), 0, 500)])->save();

            return $a;
        } catch (RouterOsException|RuntimeException $e) {
            $a->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)])->save();
            $this->audit->log('mt_accounts.create_failed', $a, 'ساخت «'.$a->username.'» روی روتر ناموفق بود: '.mb_substr($e->getMessage(), 0, 200));

            return $a;
        }
        $a->forceFill(['status' => 'active', 'ros_id' => $id, 'router_disabled' => false, 'error' => null, 'last_synced_at' => now()])->save();
        if ($a->isWireguard() && $g->rate_limit) {
            foreach ($a->fresh()->allDevices() as $dev) {
                $this->ensureQueue($a, $dev);
            }
        }

        return $a;
    }

    /**
     * WireGuard: make devices 2..N exist (panel rows and router peers) for N = deviceCount(); remove devices above N.
     * Router errors propagate (callers decide: pending / retry / message).
     */
    public function syncDevices(MtAccount $a): void
    {
        if (! $a->isWireguard()) {
            return;
        }
        $g = $a->group;
        $r = $a->router;
        $want = $a->deviceCount();
        foreach ($a->devices()->where('idx', '>', $want)->get() as $dev) {
            if ($dev->ros_id) {
                try {
                    $this->ros->remove($r, 'interface/wireguard/peers', $dev->ros_id);
                } catch (RouterOsUnreachable $e) {
                    throw $e;
                } catch (RouterOsException) {
                    // already gone
                }
            }
            $this->removeQueue($a, $dev);
            $dev->delete();
        }
        for ($i = 2; $i <= $want; $i++) {
            $dev = $a->devices()->where('idx', $i)->first();
            if (! $dev) {
                $k = WgKeys::pair();
                $dev = DB::transaction(fn () => MtDevice::query()->create(['mt_account_id' => $a->id, 'router_id' => $a->router_id, 'idx' => $i,
                    'wg_private_key' => $k['private'], 'wg_public_key' => $k['public'], 'wg_preshared_key' => WgKeys::preshared(), 'wg_address' => $this->freeAddress($g)]));
            }
            if (! $dev->ros_id) {
                $dev->forceFill(['ros_id' => $this->pushPeer($a, $dev, $g, $r), 'counters' => null])->save();
                if ($g->rate_limit) {
                    $this->ensureQueue($a, $dev);
                }
                if ($a->router_disabled) {
                    $this->ros->set($r, 'interface/wireguard/peers', $dev->ros_id, ['disabled' => 'yes']);
                }
            }
        }
        $a->unsetRelation('devices');
    }

    private function pushSecret(MtAccount $a, MtGroup $g, $r): string
    {
        foreach ($this->ros->get($r, 'ppp/secret', ['name' => $a->username]) as $row) {
            if (str_contains((string) ($row['comment'] ?? ''), 'panel:'.$a->create_key)) {
                return (string) $row['.id'];   // created by an earlier attempt whose reply was lost
            }
            throw new RuntimeException('کاربری با نام «'.$a->username.'» از قبل روی روتر وجود دارد (ساخته‌شده بیرون از پنل).');
        }

        return $this->ros->add($r, 'ppp/secret', ['name' => $a->username, 'password' => (string) $a->password, 'service' => 'l2tp',
            'profile' => (string) $g->ppp_profile, 'comment' => $a->rosComment(), 'disabled' => 'no']);
    }

    private function pushPeer(MtAccount $a, MtAccount|MtDevice $dev, MtGroup $g, $r): string
    {
        $peers = $this->ros->get($r, 'interface/wireguard/peers');
        $used = [];
        foreach ($peers as $p) {
            if (($p['public-key'] ?? '') === $dev->wg_public_key) {
                return (string) $p['.id'];
            }
            foreach (explode(',', (string) ($p['allowed-address'] ?? '')) as $aa) {
                $used[explode('/', trim($aa))[0]] = true;
            }
        }
        if (! $dev->wg_address || isset($used[$dev->wg_address])) {
            // A peer created outside the panel already holds this address: take another one.
            $dev->forceFill(['wg_address' => $this->freeAddress($g, $used)])->save();
        }

        $attrs = ['interface' => (string) $g->wg_interface, 'public-key' => (string) $dev->wg_public_key,
            'allowed-address' => $dev->wg_address.'/32', 'comment' => $a->rosComment().($dev->deviceNo() > 1 ? ' #'.$dev->deviceNo() : ''), 'disabled' => 'no'];
        if ($dev->wg_preshared_key) {
            $attrs['preshared-key'] = (string) $dev->wg_preshared_key;
        }

        return $this->ros->add($r, 'interface/wireguard/peers', $attrs);
    }

    public static function queueName(MtAccount $a, MtAccount|MtDevice $dev): string
    {
        return 'panel-'.$a->username.($dev->deviceNo() > 1 ? '-'.$dev->deviceNo() : '');
    }

    /** Per-peer speed limit (simple queue). Failure is recorded, not fatal. */
    public function ensureQueue(MtAccount $a, MtAccount|MtDevice|null $dev = null): void
    {
        $g = $a->group;
        $dev ??= $a;
        try {
            $name = self::queueName($a, $dev);
            $rows = $this->ros->get($a->router, 'queue/simple', ['name' => $name]);
            if ($rows) {
                $this->ros->set($a->router, 'queue/simple', (string) $rows[0]['.id'], ['target' => $dev->wg_address.'/32', 'max-limit' => (string) $g->rate_limit]);
            } else {
                $this->ros->add($a->router, 'queue/simple', ['name' => $name, 'target' => $dev->wg_address.'/32', 'max-limit' => (string) $g->rate_limit, 'comment' => $a->rosComment()]);
            }
        } catch (RouterOsException $e) {
            $a->forceFill(['error' => mb_substr('محدودیت سرعت اعمال نشد: '.$e->getMessage(), 0, 500)])->save();
        }
    }

    public function removeQueue(MtAccount $a, MtAccount|MtDevice $dev): void
    {
        try {
            foreach ($this->ros->get($a->router, 'queue/simple', ['name' => self::queueName($a, $dev)]) as $q) {
                $this->ros->remove($a->router, 'queue/simple', (string) $q['.id']);
            }
        } catch (RouterOsException) {
        }
    }

    /** @param array<string,bool> $alsoUsed */
    private function freeAddress(MtGroup $g, array $alsoUsed = []): string
    {
        $net = WgNetwork::parse((string) $g->wg_network);
        $used = $alsoUsed;
        foreach (MtAccount::withTrashed()->where('router_id', $g->router_id)->whereNotNull('wg_address')->pluck('wg_address') as $ip) {
            $used[$ip] = true;
        }
        foreach (MtDevice::query()->where('router_id', $g->router_id)->whereNotNull('wg_address')->pluck('wg_address') as $ip) {
            $used[$ip] = true;
        }
        if ($g->wg_server_ip) {
            $used[$g->wg_server_ip] = true;
        }

        return $net->allocate($used);
    }

    private function taken(MtGroup $g, string $username): bool
    {
        return MtAccount::withTrashed()->where('router_id', $g->router_id)->where('protocol', $g->protocol)->where('username', $username)->exists();
    }

    public function generateUsername(MtGroup $g): string
    {
        $base = preg_replace('/[^a-z0-9_]/', '', strtolower((string) app(Settings::class)->get('accounts.username_prefix', 'u'))) ?: 'u';
        $prefix = $base.($g->isWireguard() ? 'w' : 'l');
        for ($i = 0; $i < 30; $i++) {
            $name = $prefix.random_int(100000, 999999);
            if (! $this->taken($g, $name)) {
                return $name;
            }
        }
        throw new RuntimeException('ساخت نام کاربری خودکار ممکن نشد؛ یک نام وارد کنید.');
    }
}
