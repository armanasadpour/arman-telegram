<?php

namespace App\Services\Ibs;

use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Models\IbsGroup;
use App\Models\Router;
use App\Models\VpnAccount;
use App\RouterOs\RouterConnector;
use App\RouterOs\RouterOsException;
use App\Services\AuditLogger;
use App\Services\Settings;
use RuntimeException;

/**
 * Write operations on existing accounts. Each one refreshes from IBSng first (so decisions use
 * live data), performs exactly one IBSng write, refreshes again, and writes an audit record.
 * Writes are never retried automatically.
 */
class AccountActions
{
    public function __construct(
        private IbsConnector $ibs,
        private AccountSync $sync,
        private AuditLogger $audit,
        private Settings $settings,
    ) {}

    public function lock(VpnAccount $a, string $reason): VpnAccount
    {
        $a = $this->live($a);
        $this->ibs->updateAttrs($a->ibs_user_id, ['lock' => mb_substr($reason, 0, 200)]);
        $a = $this->sync->refresh($a);
        $this->audit->log('accounts.lock', $a, 'غیرفعال‌سازی (قفل) اکانت «'.$a->username.'»', ['locked' => [false, true]], $reason);

        return $a;
    }

    public function unlock(VpnAccount $a, string $reason): VpnAccount
    {
        $a = $this->live($a);
        $this->ibs->updateAttrs($a->ibs_user_id, [], ['lock']);
        $a = $this->sync->refresh($a);
        if ($a->locked) {
            throw new RuntimeException('سرور قفل را برنداشت؛ وضعیت اکانت را در سرور بررسی کنید.');
        }
        $this->audit->log('accounts.unlock', $a, 'فعال‌سازی دوباره اکانت «'.$a->username.'»', ['locked' => [true, false]], $reason);

        return $a;
    }

    public function changePassword(VpnAccount $a, string $password): VpnAccount
    {
        $a = $this->live($a);
        $this->ibs->updateAttrs($a->ibs_user_id, IbsConnector::credentialAttrs($a->username, $password));
        $a = $this->sync->refresh($a);
        $this->audit->log('accounts.password', $a, 'تغییر رمز اکانت «'.$a->username.'»', ['password' => ['***', '***']]);

        return $a;
    }

    /**
     * Extend validity by $days. Validity in IBSng is relative to first login (rel_exp_date).
     * Policy (setting accounts.renewal_mode):
     *  - smart (default): active -> add to current expiry; expired -> new period starts now.
     *  - from_expiry: always add to the current expiry (an expired account may stay expired).
     *
     * @return array{0:VpnAccount,1:int} [account, new rel_exp seconds]
     */
    public function renew(VpnAccount $a, int $days, string $reason): array
    {
        if ($days < 1 || $days > 3650) {
            throw new RuntimeException('تعداد روز نامعتبر است.');
        }
        $info = $this->liveInfo($a);
        if (isset($info['attrs']['abs_exp_date'])) {
            throw new RuntimeException('این اکانت تاریخ انقضای مطلق دارد؛ تمدید از پنل فقط برای انقضای نسبی (از اولین اتصال) پشتیبانی می‌شود.');
        }
        $a = $this->sync->apply($info) ?? $a;
        $current = $a->effectiveRelExpSeconds();
        if ($current === null) {
            throw new RuntimeException('برای این اکانت و گروهش مدت اعتبار تعریف نشده است؛ ابتدا گروه‌ها را همگام‌سازی کنید.');
        }

        $ext = $days * 86400;
        $new = $current + $ext;
        $mode = $this->settings->get('accounts.renewal_mode', 'smart');
        $remaining = isset($info['attrs']['time_to_nearest_exp_date']) ? (int) round((float) $info['attrs']['time_to_nearest_exp_date']) : null;
        if ($mode === 'smart' && $remaining !== null && $remaining < 0) {
            $new += -$remaining; // expired: skip the dead time so the new period starts now
        }

        $hours = (int) ceil($new / 3600);
        $this->ibs->updateAttrs($a->ibs_user_id, ['rel_exp_date' => $hours, 'rel_exp_date_unit' => 'Hours']);
        $a = $this->sync->refresh($a);
        $this->audit->log('accounts.renew', $a, 'تمدید «'.$a->username.'» به مدت '.$days.' روز', [
            'rel_exp_seconds' => [$current, $hours * 3600], 'mode' => [null, $mode],
        ], $reason);

        return [$a, $hours * 3600];
    }

    public function addVolume(VpnAccount $a, int $megabytes, string $reason): VpnAccount
    {
        if ($megabytes < 1 || $megabytes > 10_000_000) {
            throw new RuntimeException('مقدار حجم نامعتبر است.');
        }
        $a = $this->live($a);
        $charge = $a->charge();
        if (! $charge?->isVolume()) {
            throw new RuntimeException('تعرفه این اکانت حجمی نیست؛ افزایش حجم معنا ندارد.');
        }
        $credit = round($megabytes * 1024 * (float) $charge->cpk, 6);
        $before = (float) $a->credit;
        $baseline = max((int) $a->volume_total_bytes, (int) $a->remainingBytes());
        $this->ibs->changeCredit($a->ibs_user_id, $credit, 'panel:+'.$megabytes.'MB');
        $a = $this->sync->refresh($a);
        $a->forceFill(['volume_total_bytes' => $baseline + $megabytes * 1048576])->save();
        $this->audit->log('accounts.add_volume', $a, 'افزایش حجم «'.$a->username.'» به میزان '.$megabytes.' مگابایت', [
            'credit' => [$before, (float) $a->credit],
        ], $reason);

        return $a;
    }

    /**
     * "Like new": the validity starts again at the next first login and the volume is full again.
     * IBSng: delete the first_login attribute; if this server keeps it, the full period starts now instead.
     *
     * @return array{0: VpnAccount, 1: string} account and a Persian summary
     */
    public function resetToFirstLogin(VpnAccount $a, string $reason): array
    {
        $info = $this->liveInfo($a);
        if (isset($info['attrs']['abs_exp_date'])) {
            throw new RuntimeException('این اکانت تاریخ انقضای مطلق دارد و از اولین اتصال شمرده نمی‌شود.');
        }
        $a = $this->sync->apply($info) ?? $a;
        $rel = $a->effectiveRelExpSeconds();
        $before = ['first_login' => $a->first_login_ibs, 'credit' => (float) $a->credit];
        $notes = [];

        if ($rel && $a->first_login_ibs !== null) {
            $this->ibs->updateAttrs($a->ibs_user_id, [], ['first_login']);
            $after = $this->liveInfo($a);
            if (isset($after['attrs']['first_login'])) {
                // Server keeps first_login: give the full period from now (elapsed time + full validity).
                $elapsed = max(0, $rel - (int) round((float) ($after['attrs']['time_to_nearest_exp_date'] ?? 0)));
                $hours = (int) ceil(($elapsed + $rel) / 3600);
                $this->ibs->updateAttrs($a->ibs_user_id, ['rel_exp_date' => $hours, 'rel_exp_date_unit' => 'Hours']);
                $notes[] = 'مدت کامل ('.fa_duration($rel).') از همین الان شروع شد؛ سرور بازنشانی «اولین اتصال» را نپذیرفت';
            } else {
                $notes[] = 'زمان از اولین اتصال بعدی شروع می‌شود';
            }
        } elseif ($rel) {
            $notes[] = 'هنوز وصل نشده بود؛ زمان از اولین اتصال شروع می‌شود';
        }

        $charge = $a->charge();
        if ($charge?->isVolume() && (int) $a->volume_total_bytes > 0) {
            $target = round(((int) $a->volume_total_bytes / 1024) * (float) $charge->cpk, 6);
            $delta = round($target - (float) $a->credit, 6);
            if (abs($delta) > 0.000001) {
                $this->ibs->changeCredit($a->ibs_user_id, $delta, 'panel:reset');
            }
            $notes[] = 'حجم به '.fa_bytes((int) $a->volume_total_bytes).' برگشت';
        } elseif ($charge?->isVolume()) {
            $notes[] = 'حجم کامل این اکانت معلوم نیست (بسته‌ای از پنل نگرفته)؛ حجم تغییر نکرد';
        }
        $a = $this->sync->refresh($a);
        $this->audit->log('accounts.reset_first_login', $a, 'بازنشانی تاریخ و حجم «'.$a->username.'» به حالت اولین اتصال', [
            'first_login' => [$before['first_login'], $a->first_login_ibs], 'credit' => [$before['credit'], (float) $a->credit],
        ], $reason);

        return [$a, implode('، ', $notes).'.'];
    }

    /** Change the customer name (IBSng comment; the panel's tracking prefix is kept). */
    public function setCustomerName(VpnAccount $a, string $name): VpnAccount
    {
        $a = $this->live($a);
        $old = $a->displayComment();
        $prefix = preg_match('/^(panel:[0-9a-f-]{36})/', (string) $a->comment, $m) ? $m[1].' ' : '';
        $comment = mb_substr($prefix.trim($name), 0, 250);
        $this->ibs->updateAttrs($a->ibs_user_id, ['comment' => $comment]);
        $a->forceFill(['comment' => $comment])->save();
        $this->audit->log('accounts.edit', $a, 'ویرایش اطلاعات «'.$a->username.'»', ['customer' => [$old, trim($name)]]);

        return $a;
    }

    /** Move the account to another IBSng group (same charge type, so credit keeps its meaning). */
    public function setGroup(VpnAccount $a, string $group): VpnAccount
    {
        $a = $this->live($a);
        if ($a->group_name === $group) {
            return $a;
        }
        $new = IbsGroup::query()->with('charge')->where('name', $group)->where('present', true)->first()
            ?? throw new RuntimeException('این گروه در سرور وجود ندارد.');
        if ((bool) $new->charge?->isVolume() !== $a->isVolumeLimited()) {
            throw new RuntimeException('تعرفه گروه جدید '.($new->charge?->isVolume() ? 'حجمی' : 'بدون محدودیت حجم').' است و با تعرفه فعلی اکانت فرق دارد؛ فقط بین گروه‌های هم‌نوع می‌شود جابه‌جا کرد.');
        }
        $old = $a->group_name;
        $this->ibs->updateAttrs($a->ibs_user_id, ['group_name' => $group]);
        $a = $this->sync->refresh($a);
        $this->audit->log('accounts.group', $a, 'تغییر گروه «'.$a->username.'»: '.$old.' ← '.$group, ['group_name' => [$old, $a->group_name]]);

        return $a;
    }

    /** Disconnect the account's live OpenVPN sessions. @return int sessions disconnected */
    /**
     * Disconnect the account's live OpenVPN sessions:
     *   1. ask IBSng (user.killUser) — IBSng itself reaches the RAS, which may not work with RouterOS 7;
     *   2. remove the user's /ppp/active entries directly on every MikroTik connected to the panel;
     *   3. check IBSng's online list again and report what is still connected.
     *
     * @return array{killed:int, router:int, still:int, errors:list<string>}
     */
    public function kick(VpnAccount $a): array
    {
        $r = ['killed' => 0, 'router' => 0, 'still' => 0, 'errors' => []];
        foreach ($this->ibs->onlineUsers() as $s) {
            if ((int) ($s['user_id'] ?? 0) === $a->ibs_user_id || ($s['normal_username'] ?? null) === $a->username) {
                try {
                    $this->ibs->killUser($a->ibs_user_id, (string) ($s['ras_ip'] ?? ''), (string) ($s['unique_id_val'] ?? ''));
                    $r['killed']++;
                } catch (IbsException $e) {
                    $r['errors'][] = 'IBSng: '.mb_substr($e->getMessage(), 0, 120);
                }
            }
        }
        $ros = app(RouterConnector::class);
        foreach (Router::query()->where('is_active', true)->get() as $router) {
            try {
                foreach ($ros->get($router, 'ppp/active', ['name' => $a->username]) as $row) {
                    $ros->remove($router, 'ppp/active', (string) $row['.id']);
                    $r['router']++;
                }
            } catch (RouterOsException $e) {
                $r['errors'][] = $router->name.': '.mb_substr($e->getMessage(), 0, 120);
            }
        }
        usleep(app()->runningUnitTests() ? 0 : 1_500_000);   // give IBSng a moment to see the RAS drop the session
        try {
            $a = $this->sync->refreshOnline($a);
            $r['still'] = (int) $a->online_sessions;
        } catch (\Throwable) {
            $a->forceFill(['is_online' => false, 'online_sessions' => 0, 'sessions' => null])->save();
        }
        $this->audit->log('accounts.kick', $a, 'قطع نشست‌های «'.$a->username.'»: IBSng '.fa($r['killed']).'، روتر '.fa($r['router']).($r['still'] ? '، هنوز وصل: '.fa($r['still']) : ''));

        return $r;
    }

    public function delete(VpnAccount $a, string $reason): void
    {
        $a = $this->live($a);
        $this->ibs->deleteUser($a->ibs_user_id, mb_substr('panel: '.$reason, 0, 200));
        $a->forceFill(['ibs_deleted_at' => now(), 'is_online' => false])->save();
        $this->audit->log('accounts.delete', $a, 'حذف اکانت «'.$a->username.'» از سرور', ['ibs_user_id' => [$a->ibs_user_id, null]], $reason);
        $a->delete();
    }

    /** Sensitive: read the current password from IBSng (never stored by the panel). */
    public function revealPassword(VpnAccount $a): string
    {
        $info = $this->liveInfo($a);
        $pw = $info['attrs']['normal_password'] ?? null;
        if ($pw === null) {
            throw new RuntimeException('سرور رمز این اکانت را برنگرداند.');
        }
        $this->audit->log('accounts.password_revealed', $a, 'مشاهده رمز اکانت «'.$a->username.'»');

        return (string) $pw;
    }

    private function live(VpnAccount $a): VpnAccount
    {
        $a = $this->sync->refresh($a);
        if ($a->ibs_deleted_at) {
            throw new RuntimeException('این اکانت در سرور وجود ندارد.');
        }

        return $a;
    }

    /** @return array<string,mixed> */
    private function liveInfo(VpnAccount $a): array
    {
        $info = $this->ibs->usersByIds([$a->ibs_user_id])[(string) $a->ibs_user_id] ?? null;
        if (! $info) {
            throw new RuntimeException('این اکانت در سرور وجود ندارد.');
        }

        return $info;
    }
}
