<?php

namespace App\Services\Ibs;

use App\Ibs\ErrorMessages;
use App\Ibs\IbsConnector;
use App\Ibs\IbsException;
use App\Ibs\IbsUnavailableException;
use App\Models\IbsGroup;
use App\Models\ProvisioningJob;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Account creation in IBSng is two non-atomic calls (phase 1 finding):
 *   1. user.addNewUsers     -> raw user id (no username yet, cannot log in)
 *   2. user.updateUserAttrs -> username, password, comment, overrides
 * Every run is recorded in provisioning_jobs keyed by an idempotency key, so a double
 * submit, a timeout or a crash never creates a second account:
 *   - same key submitted again  -> the existing job is resumed/returned, not duplicated;
 *   - step 1 timed out          -> needs_review (we cannot know if a raw user exists);
 *   - step 2 failed             -> retried on the same user id, or the raw user is deleted (compensated).
 */
class Provisioning
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private IbsConnector $ibs, private AccountSync $sync, private AuditLogger $audit) {}

    /**
     * @param  array{username:?string,password:?string,group:string,days:?int,multi_login:?int,volume_mb:?int,comment:?string}  $spec
     */
    public function create(string $jobKey, array $spec, ?int $actorId = null): ProvisioningJob
    {
        if ($existing = ProvisioningJob::query()->where('job_key', $jobKey)->first()) {
            return in_array($existing->status, ['pending', 'raw_created'], true) ? $this->run($existing) : $existing;
        }

        $group = IbsGroup::query()->where('name', $spec['group'])->where('present', true)->first()
            ?? throw new RuntimeException('گروه انتخاب‌شده در سرور موجود نیست. ابتدا گروه‌ها را همگام‌سازی کنید.');
        $charge = $group->charge;
        if ($charge?->isVolume() && empty($spec['volume_mb'])) {
            throw new RuntimeException('این گروه تعرفه حجمی دارد؛ حجم (مگابایت) را وارد کنید.');
        }
        if (! $charge?->isVolume() && ! empty($spec['volume_mb'])) {
            throw new RuntimeException('تعرفه این گروه حجمی نیست؛ حجم قابل تعیین نیست.');
        }

        $username = $spec['username'] ?: $this->generateUsername();
        if (VpnAccount::query()->where('username', $username)->exists()
            || VpnAccount::onlyTrashed()->where('username', $username)->whereNull('ibs_deleted_at')->exists()
            || ProvisioningJob::query()->where('username', $username)->whereIn('status', ProvisioningJob::OPEN)->exists()) {
            throw new RuntimeException('این نام کاربری در پنل استفاده شده است.');
        }
        if (! $this->ibs->usernameAvailable($username)) {
            throw new RuntimeException('این نام کاربری در سرور وجود دارد (ولی هنوز در پنل نیست) یا کاراکتر غیرمجاز دارد. اگر اکانت قبلی است، از پایین صفحه اکانت‌ها «وارد کردن یک اکانت از سرور» را بزنید.');
        }
        // A deleted account (gone from the server) frees its name: its panel history keeps a renamed copy.
        foreach (VpnAccount::onlyTrashed()->where('username', $username)->whereNotNull('ibs_deleted_at')->get() as $old) {
            $old->forceFill(['username' => mb_substr($username, 0, 50).'~'.$old->id])->saveQuietly();
        }

        $password = $spec['password'] ?: self::generatePassword();
        $job = ProvisioningJob::query()->create([
            'job_key' => $jobKey,
            'username' => $username,
            'status' => 'pending',
            'spec' => [
                'group' => $group->name,
                'days' => $spec['days'] ?? null,
                'multi_login' => $spec['multi_login'] ?? null,
                'volume_mb' => $spec['volume_mb'] ?? null,
                'credit' => $charge?->isVolume() ? round(((int) $spec['volume_mb']) * 1024 * (float) $charge->cpk, 6) : 0.0,
                'comment' => $spec['comment'] ?? null,
                'package' => $spec['package'] ?? null, // price snapshot when created from a package
            ],
            'password_encrypted' => Crypt::encryptString($password),
            'created_by' => $actorId,
        ]);

        return $this->run($job);
    }

    /** Advance a job as far as possible. Safe to call repeatedly. */
    public function run(ProvisioningJob $job): ProvisioningJob
    {
        // A lock (not a DB transaction): every step's result must be committed immediately,
        // otherwise a later failure would roll back the record of what IBSng already did.
        $lock = Cache::lock('provisioning:'.$job->id, 120);
        if (! $lock->block(10)) {
            throw new RuntimeException('این درخواست در حال پردازش است؛ چند لحظه بعد صفحه را تازه کنید.');
        }
        try {
            $job = ProvisioningJob::query()->findOrFail($job->id);
            if ($job->status === 'pending') {
                $this->stepCreateRaw($job);
            }
            if ($job->status === 'raw_created') {
                $this->stepSetAttributes($job);
            }

            return $job->fresh();
        } finally {
            $lock->release();
        }
    }

    private function stepCreateRaw(ProvisioningJob $job): void
    {
        $job->attempts++;
        try {
            $id = $this->ibs->addRawUser($job->spec['group'], (float) $job->spec['credit'], 'panel-job:'.$job->job_key);
            $job->fill(['ibs_user_id' => $id, 'status' => 'raw_created', 'last_error' => null])->save();
        } catch (IbsException $e) {
            // IBSng refused: nothing was created.
            $this->finish($job, 'failed', ErrorMessages::for($e));
        } catch (IbsUnavailableException $e) {
            // We cannot know whether IBSng created the raw user before the connection dropped.
            $job->fill(['status' => 'needs_review', 'last_error' => 'پاسخ مرحله ۱ (ساخت کاربر خام) دریافت نشد؛ ممکن است یک کاربر بدون نام کاربری در IBSng ساخته شده باشد. '
                .'در IBSng کاربران گروه «'.$job->spec['group'].'» را که نام کاربری ندارند و توضیح اعتبار «panel-job:'.$job->job_key.'» دارند بررسی کنید.'])->save();
            $this->audit->log('accounts.provision_review', $job, 'ساخت اکانت «'.$job->username.'» نیازمند رسیدگی', null, $job->last_error);
        }
    }

    private function stepSetAttributes(ProvisioningJob $job): void
    {
        $job->attempts++;
        $attrs = IbsConnector::credentialAttrs($job->username, Crypt::decryptString((string) $job->password_encrypted))
            + ['comment' => mb_substr('panel:'.$job->job_key.($job->spec['comment'] ? ' '.$job->spec['comment'] : ''), 0, 250)];
        if (! empty($job->spec['days'])) {
            $attrs += ['rel_exp_date' => (int) $job->spec['days'], 'rel_exp_date_unit' => 'Days'];
        }
        if ($job->spec['multi_login'] !== null && $job->spec['multi_login'] !== '') {
            $attrs['multi_login'] = (int) $job->spec['multi_login'];
        }

        try {
            $this->ibs->updateAttrs((int) $job->ibs_user_id, $attrs);
        } catch (IbsException $e) {
            // IBSng rejected the attributes (e.g. username taken meanwhile): remove the raw user.
            $this->compensate($job, ErrorMessages::for($e));

            return;
        } catch (IbsUnavailableException $e) {
            // Did the update land? Check before deciding.
            if (! $this->attributesLanded($job)) {
                $job->last_error = ErrorMessages::for($e);
                if ($job->attempts >= self::MAX_ATTEMPTS) {
                    $job->status = 'needs_review';
                    $this->audit->log('accounts.provision_review', $job, 'ساخت اکانت «'.$job->username.'» پس از چند تلاش کامل نشد', null, $job->last_error);
                }
                $job->save();

                return;
            }
        }

        try {
            $info = $this->ibs->usersByIds([(int) $job->ibs_user_id])[(string) $job->ibs_user_id] ?? null;
        } catch (\Throwable $e) {
            $info = null;
        }
        $account = $info ? $this->sync->apply($info, 'panel') : null;
        if (! $account) {
            // Attributes are set in IBSng; the next run (or cron retry) only needs to read it back.
            $job->save();
            $job->fill(['last_error' => 'اکانت ساخته شد اما خواندن آن از IBSng ناموفق بود؛ دوباره تلاش می‌شود.'])->save();

            return;
        }
        $account->forceFill([
            'created_by' => $job->created_by,
            'volume_total_bytes' => ! empty($job->spec['volume_mb']) ? (int) $job->spec['volume_mb'] * 1048576 : null,
        ])->save();
        $job->vpn_account_id = $account->id;
        $this->finish($job, 'completed');
        $this->audit->log('accounts.create', $account, 'ساخت اکانت «'.$account->username.'» در گروه «'.$job->spec['group'].'»', [
            'group' => [null, $job->spec['group']], 'days' => [null, $job->spec['days']], 'multi_login' => [null, $job->spec['multi_login']],
            'volume_mb' => [null, $job->spec['volume_mb']], 'ibs_user_id' => [null, $account->ibs_user_id],
        ]);
    }

    private function attributesLanded(ProvisioningJob $job): bool
    {
        try {
            $info = $this->ibs->usersByIds([(int) $job->ibs_user_id])[(string) $job->ibs_user_id] ?? null;

            return ($info['attrs']['normal_username'] ?? null) === $job->username;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Delete the raw user created in step 1. */
    public function compensate(ProvisioningJob $job, string $reason): void
    {
        try {
            if ($job->ibs_user_id) {
                $this->ibs->deleteUser((int) $job->ibs_user_id, 'panel compensation '.$job->job_key);
            }
            $this->finish($job, 'compensated', $reason);
            $this->audit->log('accounts.provision_compensated', $job, 'ساخت «'.$job->username.'» ناموفق؛ کاربر خام از IBSng حذف شد', null, $reason);
        } catch (IbsException $e) {
            if ($e->ibsCode === 'USERID_DOESNT_EXISTS') {
                $this->finish($job, 'compensated', $reason);

                return;
            }
            $this->markReview($job, $reason.' | حذف کاربر خام هم ناموفق بود: '.ErrorMessages::for($e));
        } catch (IbsUnavailableException $e) {
            $this->markReview($job, $reason.' | حذف کاربر خام هم ناموفق بود: '.ErrorMessages::for($e));
        }
    }

    /** Admin decision for a needs_review job: retry step 2 (known raw id) or mark resolved. */
    public function retry(ProvisioningJob $job): ProvisioningJob
    {
        if ($job->status === 'needs_review' && $job->ibs_user_id) {
            $job->fill(['status' => 'raw_created', 'attempts' => 0])->save();

            return $this->run($job);
        }
        if (in_array($job->status, ['pending', 'raw_created'], true)) {
            return $this->run($job);
        }

        throw new RuntimeException('این مورد قابل تلاش دوباره نیست.');
    }

    public function resolveManually(ProvisioningJob $job, string $note): void
    {
        $this->finish($job, 'failed', 'بسته‌شده به‌صورت دستی: '.$note);
        $this->audit->log('accounts.provision_resolved', $job, 'بستن دستی ساخت «'.$job->username.'»', null, $note);
    }

    private function markReview(ProvisioningJob $job, string $error): void
    {
        $job->fill(['status' => 'needs_review', 'last_error' => mb_substr($error, 0, 1000)])->save();
        $this->audit->log('accounts.provision_review', $job, 'ساخت «'.$job->username.'» نیازمند رسیدگی', null, $error);
    }

    private function finish(ProvisioningJob $job, string $status, ?string $error = null): void
    {
        $job->fill([
            'status' => $status, 'last_error' => $error ? mb_substr($error, 0, 1000) : null,
            'finished_at' => now(), 'password_encrypted' => null, // password not kept after the job ends
        ])->save();
    }

    public function generateUsername(): string
    {
        $prefix = preg_replace('/[^a-z0-9_]/', '', strtolower((string) app(Settings::class)->get('accounts.username_prefix', 'u'))) ?: 'u';
        for ($i = 0; $i < 20; $i++) {
            $name = $prefix.random_int(100000, 999999);
            if (! VpnAccount::withTrashed()->where('username', $name)->exists() && ! ProvisioningJob::query()->where('username', $name)->exists()) {
                return $name;
            }
        }

        return $prefix.Str::lower(Str::random(10));
    }

    /** 10 chars, no ambiguous characters (0/O, 1/l/I). */
    public static function generatePassword(int $len = 10): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }
}
