<?php

namespace App\Services\Finance;

use App\Bots\Notifier;
use App\Ibs\ErrorMessages;
use App\Ibs\IbsException;
use App\Ibs\IbsNotConfiguredException;
use App\Ibs\IbsUnavailableException;
use App\Models\BotUser;
use App\Models\CustomerOrder;
use App\Models\MtAccount;
use App\Models\MtDevice;
use App\Models\Package;
use App\Models\ProvisioningJob;
use App\Models\VpnAccount;
use App\Services\Ibs\Provisioning;
use App\Services\Mikrotik\MtActions;
use App\Services\Mikrotik\MtProvisioning;
use App\Services\Mikrotik\WgConfig;
use App\Services\Pricing\PackageApplier;
use App\Services\Pricing\PackageRules;
use App\Services\Pricing\PricingEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A bot customer's purchase at the public price, paid from the customer's wallet (owner_type "customer",
 * owner_id = bot_user id). Payments top the wallet up first; fulfil() then debits and provisions — the same
 * saga as reseller sales: definite failure -> refund to the wallet, uncertain -> needs_review until resolved.
 */
class CustomerOrders
{
    public function __construct(
        private WalletService $wallets,
        private PricingEngine $pricing,
        private Provisioning $provisioning,
        private PackageApplier $applier,
        private Notifier $notifier,
    ) {}

    public function create(BotUser $customer, Package $package, VpnAccount|MtAccount|null $target = null): CustomerOrder
    {
        if (! $package->is_active) {
            throw new RuntimeException('این بسته فعال نیست.');
        }
        if ($package->type === 'new') {
            if ($target) {
                throw new RuntimeException('بسته اکانت جدید روی اکانت موجود اعمال نمی‌شود.');
            }
        } else {
            $mine = $target instanceof MtAccount ? $customer->mtAccounts()->whereKey($target->id)->exists()
                : ($target && $customer->accounts()->whereKey($target->id)->exists());
            if (! $target || ! $mine) {
                throw new RuntimeException('این اکانت در فهرست اکانت‌های شما نیست.');
            }
            if ($why = $target instanceof MtAccount ? MtActions::applicability($package, $target) : PackageRules::applicability($package, $target)) {
                throw new RuntimeException($why);
            }
        }
        $q = $this->pricing->quote($package);

        return CustomerOrder::query()->create([
            'order_key' => (string) Str::uuid(), 'bot_user_id' => $customer->id, 'package_id' => $package->id, 'type' => $package->type,
            'price_snapshot' => $q->snapshot(), 'price_rial' => $q->finalPrice,
            'vpn_account_id' => $target instanceof VpnAccount ? $target->id : null, 'mt_account_id' => $target instanceof MtAccount ? $target->id : null,
        ]);
    }

    public function walletBalance(BotUser $customer): int
    {
        return $this->wallets->walletFor('customer', $customer->id)->balance;
    }

    /** Debit and provision. Idempotent; does nothing until the wallet holds enough. */
    public function fulfil(CustomerOrder $order): CustomerOrder
    {
        $lock = Cache::lock('order:'.$order->id, 120);
        if (! $lock->get()) {
            return $order;
        }
        try {
            $order->refresh();
            if ($order->status !== 'awaiting_payment') {
                return $this->sync($order);
            }
            $wallet = $this->wallets->walletFor('customer', $order->bot_user_id);
            $package = $order->package;
            if ($order->price_rial > 0) {
                try {
                    $debit = $this->wallets->post($wallet, -$order->price_rial, 'purchase', "order:{$order->id}:debit", 'خرید «'.$package->name.'»', null, null, $order);
                } catch (InsufficientBalanceException) {
                    return $order;   // stays awaiting_payment; the money already paid stays in the wallet
                }
                $order->debit_entry_id = $debit->id;
            }
            $order->status = 'pending';
            $order->save();

            if ($package->protocol === 'mikrotik') {
                return $this->fulfilMikrotik($order, $package);
            }
            if ($order->type === 'new') {
                $password = Provisioning::generatePassword();
                $order->secret = $password;
                $order->save();
                try {
                    $job = $this->provisioning->create($order->order_key, [
                        'username' => null, 'password' => $password, 'group' => (string) $package->ibs_group_name,
                        'days' => $package->duration_days, 'multi_login' => $package->multi_login, 'volume_mb' => $package->volumeMb(),
                        'comment' => 'bot #'.$order->bot_user_id, 'package' => $order->price_snapshot,
                    ], null);
                } catch (Throwable $e) {
                    $this->refund($order, $this->message($e));

                    return $order;
                }
                $order->provisioning_job_id = $job->id;
                $order->save();

                return $this->applyJob($order);
            }
            try {
                $this->applier->applyToAccount($package, $order->account, 'خرید مشتری ربات — سفارش #'.$order->id);
                $order->fill(['status' => 'completed'])->save();
                $this->notifyDone($order);
            } catch (IbsUnavailableException $e) {
                $order->fill(['status' => 'needs_review', 'error' => $this->message($e)])->save();
                $this->notifyReview($order);
            } catch (Throwable $e) {
                $this->refund($order, $this->message($e));
            }

            return $order;
        } finally {
            $lock->release();
        }
    }

    private function fulfilMikrotik(CustomerOrder $order, Package $package): CustomerOrder
    {
        if ($order->type !== 'new') {
            try {
                app(MtActions::class)->applyPackage($package, $order->mtAccount, 'خرید مشتری ربات — سفارش #'.$order->id);
                $order->fill(['status' => 'completed'])->save();
                $this->notifyDone($order);
            } catch (Throwable $e) {
                $this->refund($order, $this->message($e));
            }

            return $order;
        }
        try {
            if (! $package->mtGroup) {
                throw new RuntimeException('گروه میکروتیک این بسته وجود ندارد.');
            }
            $a = app(MtProvisioning::class)->create($package->mtGroup, $order->order_key, [
                'days' => $package->duration_days, 'volume_gb' => $package->volume_gb, 'multi_login' => $package->multi_login,
                'comment' => 'bot #'.$order->bot_user_id, 'package_id' => $package->id,
            ], null);
        } catch (Throwable $e) {
            $this->refund($order, $this->message($e));

            return $order;
        }
        $order->forceFill(['mt_account_id' => $a->id])->save();

        return $this->applyMt($order);
    }

    private function applyMt(CustomerOrder $order): CustomerOrder
    {
        $a = MtAccount::withTrashed()->find($order->mt_account_id);
        if (! $a || $order->type !== 'new') {
            return $order;
        }
        if ($a->status === 'failed') {
            $this->refund($order, (string) $a->error);
        } elseif ($a->status !== 'pending') {
            $order->fill(['status' => 'completed', 'error' => null])->save();
            $order->botUser->mtAccounts()->syncWithoutDetaching([$a->id => ['created_at' => now()]]);
            $this->notifyDone($order);
        }

        return $order;
    }

    /** Cron: MikroTik orders whose router was unreachable at purchase time. */
    public function syncPendingMt(): int
    {
        $n = 0;
        foreach (CustomerOrder::query()->where('status', 'pending')->whereNotNull('mt_account_id')->limit(100)->get() as $o) {
            $n += (int) ($this->applyMt($o)->status !== 'pending');
        }

        return $n;
    }

    /** Cron: orders whose provisioning job finished later. */
    public function syncPending(): int
    {
        $n = 0;
        foreach (CustomerOrder::query()->whereIn('status', ['pending', 'needs_review'])->whereNotNull('provisioning_job_id')->limit(100)->get() as $o) {
            $before = $o->status;
            $n += (int) ($this->sync($o)->status !== $before);
        }

        return $n;
    }

    public function sync(CustomerOrder $order): CustomerOrder
    {
        if ($order->status === 'pending' && $order->mt_account_id) {
            return $this->applyMt($order);
        }

        return in_array($order->status, ['pending', 'needs_review'], true) && $order->provisioning_job_id ? $this->applyJob($order) : $order;
    }

    /** Admin decision on a needs_review order: give the money back to the customer's wallet. */
    public function adminRefund(CustomerOrder $order, string $reason, int $actorId): CustomerOrder
    {
        if (! in_array($order->status, ['needs_review', 'pending'], true)) {
            throw new RuntimeException('فقط سفارش‌های در انتظار یا نیازمند رسیدگی قابل برگشت هستند.');
        }
        $this->refund($order, 'برگشت توسط مدیر: '.$reason, $actorId);

        return $order->fresh();
    }

    private function applyJob(CustomerOrder $order): CustomerOrder
    {
        $job = ProvisioningJob::query()->find($order->provisioning_job_id);
        if (! $job) {
            return $order;
        }
        if ($job->status === 'completed') {
            $order->fill(['status' => 'completed', 'vpn_account_id' => $job->vpn_account_id, 'error' => null])->save();
            $order->botUser->accounts()->syncWithoutDetaching([$job->vpn_account_id => ['created_at' => now()]]);
            $this->notifyDone($order);
        } elseif (in_array($job->status, ['failed', 'compensated'], true)) {
            $this->refund($order, (string) $job->last_error);
        } elseif ($job->status === 'needs_review' && $order->status !== 'needs_review') {
            $order->fill(['status' => 'needs_review', 'error' => $job->last_error])->save();
            $this->notifyReview($order);
        }

        return $order;
    }

    private function refund(CustomerOrder $order, string $why, ?int $actorId = null): void
    {
        if ($order->debit_entry_id && ! $order->refund_entry_id) {
            $e = $this->wallets->post($this->wallets->walletFor('customer', $order->bot_user_id), $order->price_rial, 'refund', "order:{$order->id}:refund",
                'بازگشت وجه سفارش #'.$order->id, mb_substr($why, 0, 450), $actorId, $order);
            $order->refund_entry_id = $e->id;
        }
        $order->fill(['status' => 'refunded', 'error' => mb_substr($why, 0, 500), 'secret' => null])->save();
        $this->notifier->toBotUser($order->bot_user_id, '❗ سفارش #'.fa((string) $order->id).' انجام نشد. مبلغ '.fa_money($order->price_rial)
            .' در کیف پول شما در ربات ماند و می‌توانید با آن دوباره خرید کنید.');
    }

    private function notifyDone(CustomerOrder $order): void
    {
        if (! Cache::add('order.done.'.$order->id, 1, now()->addDays(2))) {
            return;
        }
        if ($order->mt_account_id) {
            $m = MtAccount::query()->find($order->mt_account_id);
            if ($m && $order->type === 'new') {
                $this->notifier->toBotUser($order->bot_user_id, "✅ خرید انجام شد.\n\n".self::mtCredentials($m)."\n\nبسته: ".$order->package?->name."\nاین پیام را جای امنی نگه دارید.", true);
            } elseif ($m) {
                $this->notifier->toBotUser($order->bot_user_id, '✅ اکانت '.$m->username.' با بسته «'.$order->package?->name.'» به‌روزرسانی شد.');
            }

            return;
        }
        $a = $order->account()->first();
        if ($order->type === 'new') {
            $this->notifier->toBotUser($order->bot_user_id, "✅ خرید انجام شد.\n\nنام کاربری: ".($a?->username ?? '—')."\nرمز: ".($order->secret ?? '—')
                ."\nبسته: ".$order->package?->name."\n\nاین پیام را جای امنی نگه دارید.", true);
            $order->forceFill(['secret' => null])->save();
        } else {
            $this->notifier->toBotUser($order->bot_user_id, '✅ اکانت '.($a?->username ?? '').' با بسته «'.$order->package?->name.'» به‌روزرسانی شد.');
        }
    }

    /** Connection details for a messenger (contains secrets: send as a sensitive message). */
    public static function mtCredentials(MtAccount $m): string
    {
        $g = $m->group;
        if ($m->isWireguard()) {
            $out = '🔐 WireGuard — '.$m->username.($m->deviceCount() > 1 ? ' ('.fa($m->deviceCount()).' دستگاه؛ هر فایل فقط روی یک دستگاه)' : '')
                ."\nفایل زیر را در برنامه WireGuard با «Import from text» یا ساخت فایل .conf وارد کنید:";
            foreach ($m->allDevices() as $dev) {
                $out .= "\n\n".($m->deviceCount() > 1 ? '📱 دستگاه '.fa($dev->deviceNo())."\n" : '').WgConfig::build($m, $dev instanceof MtDevice ? $dev : null);
            }

            return $out;
        }
        $lines = ['🔐 L2TP/IPsec', 'سرور: '.$g->endpoint(), 'نام کاربری: '.$m->username, 'رمز: '.$m->password];
        if ($g->ipsec_psk) {
            $lines[] = 'کلید IPsec (Pre-shared key): '.$g->ipsec_psk;
        }

        return implode("\n", $lines);
    }

    private function notifyReview(CustomerOrder $order): void
    {
        $this->notifier->toBotUser($order->bot_user_id, '⏳ سفارش #'.fa((string) $order->id).' در حال بررسی است؛ نتیجه را همین‌جا اطلاع می‌دهیم.');
        $this->notifier->toStaff('payments.view', '⚠️ سفارش مشتری #'.fa((string) $order->id).' نیازمند رسیدگی است (نتیجه در سرور نامشخص).');
    }

    private function message(Throwable $e): string
    {
        return $e instanceof IbsException || $e instanceof IbsUnavailableException || $e instanceof IbsNotConfiguredException
            ? ErrorMessages::for($e) : $e->getMessage();
    }
}
