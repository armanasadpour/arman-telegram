<?php

namespace App\Services\Finance;

use App\Ibs\ErrorMessages;
use App\Ibs\IbsUnavailableException;
use App\Models\Package;
use App\Models\ProvisioningJob;
use App\Models\Reseller;
use App\Models\ResellerSale;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Ibs\Provisioning;
use App\Services\Pricing\PackageApplier;
use App\Services\Pricing\PackageRules;
use RuntimeException;
use Throwable;

/**
 * A reseller sale: debit the reseller's wallet, then act on the server.
 *   - definite failure (nothing created/changed)  -> automatic refund (separate ledger row)
 *   - uncertain outcome (connection lost mid-way) -> needs_review, money stays debited until
 *     the provisioning job resolves (cron: syncPending) or an admin decides.
 * Idempotent on sale_key: resubmitting the form never debits or provisions twice.
 */
class ResellerSales
{
    public function __construct(
        private WalletService $wallets,
        private ResellerPricing $pricing,
        private Provisioning $provisioning,
        private PackageApplier $applier,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{customer_name:?string,customer_phone:?string,username:?string}  $opts
     * @return array{sale: ResellerSale, password: ?string}
     */
    public function sell(Reseller $reseller, Package $package, string $saleKey, int $salePriceRial, array $opts, ?VpnAccount $target, int $actorId): array
    {
        if ($existing = ResellerSale::query()->where('sale_key', $saleKey)->first()) {
            if ($existing->reseller_id !== $reseller->id) {
                throw new RuntimeException('شناسه فروش نامعتبر است.');
            }

            return ['sale' => $this->syncOne($existing), 'password' => null];
        }
        if (! $reseller->isActive()) {
            throw new RuntimeException('حساب همکاری شما غیرفعال است.');
        }
        if (! $package->is_active) {
            throw new RuntimeException('این بسته فعال نیست.');
        }
        if ($package->type === 'new') {
            if ($target) {
                throw new RuntimeException('بسته اکانت جدید روی اکانت موجود اعمال نمی‌شود.');
            }
        } else {
            if (! $target || $target->reseller_id !== $reseller->id) {
                throw new RuntimeException('این اکانت متعلق به شما نیست.');
            }
            if ($why = PackageRules::applicability($package, $target)) {
                throw new RuntimeException($why);
            }
        }
        if ($salePriceRial < 0) {
            throw new RuntimeException('قیمت فروش نامعتبر است.');
        }

        $quote = $this->pricing->quote($reseller, $package);
        $cost = $quote->finalPrice;
        $sale = ResellerSale::query()->create([
            'sale_key' => $saleKey,
            'reseller_id' => $reseller->id,
            'package_id' => $package->id,
            'type' => $package->type,
            'price_snapshot' => $quote->snapshot(),
            'cost_rial' => $cost,
            'sale_price_rial' => $salePriceRial,
            'profit_rial' => $salePriceRial - $cost,
            'customer_name' => $opts['customer_name'] ?? null,
            'customer_phone' => $opts['customer_phone'] ?? null,
            'vpn_account_id' => $target?->id,
            'status' => 'pending',
        ]);

        // 1. Debit first: no credit, no service.
        $wallet = $this->wallets->walletFor('reseller', $reseller->id);
        if ($cost > 0) {
            try {
                $debit = $this->wallets->post($wallet, -$cost, 'purchase', "sale:{$sale->id}:debit",
                    'خرید «'.$package->name.'»', null, $actorId, $sale);
            } catch (InsufficientBalanceException $e) {
                $sale->fill(['status' => 'failed', 'error' => $e->getMessage()])->save();

                throw $e;
            }
            $sale->debit_entry_id = $debit->id;
            $sale->save();
        }

        // 2. Act on the server.
        $password = null;
        if ($package->type === 'new') {
            $password = Provisioning::generatePassword();
            try {
                $job = $this->provisioning->create($saleKey, [
                    'username' => $opts['username'] ?? null,
                    'password' => $password,
                    'group' => (string) $package->ibs_group_name,
                    'days' => $package->duration_days,
                    'multi_login' => $package->multi_login,
                    'volume_mb' => $package->volumeMb(),
                    'comment' => trim($reseller->code.' '.($opts['customer_name'] ?? '')),
                    'package' => $quote->snapshot(),
                ], $actorId);
            } catch (Throwable $e) {
                // Refused before anything was sent to the server (validation, username taken, server down at pre-check).
                $this->refund($sale, $e instanceof RuntimeException ? $this->message($e) : 'خطای غیرمنتظره');
                throw new RuntimeException($this->message($e));
            }
            $sale->provisioning_job_id = $job->id;
            $sale->save();
            $sale = $this->applyJobOutcome($sale, $job);
        } else {
            try {
                $this->applier->applyToAccount($package, $target, 'فروش همکار '.$reseller->code.' #'.$sale->id);
                $sale->fill(['status' => 'completed'])->save();
            } catch (IbsUnavailableException $e) {
                $sale->fill(['status' => 'needs_review', 'error' => $this->message($e)])->save();
            } catch (Throwable $e) {
                $this->refund($sale, $this->message($e));
            }
        }

        $this->audit->log('resellers.sale', $sale, 'فروش همکار «'.$reseller->code.'»: '.$package->name, [
            'cost_rial' => [null, $sale->cost_rial], 'sale_price_rial' => [null, $sale->sale_price_rial], 'status' => [null, $sale->status],
        ]);

        return ['sale' => $sale->fresh(), 'password' => $sale->status === 'completed' ? $password : null];
    }

    /** Follow up sales whose provisioning finished later (cron / page view). */
    public function syncPending(): int
    {
        $n = 0;
        ResellerSale::query()->whereIn('status', ['pending', 'needs_review'])->whereNotNull('provisioning_job_id')->with('job')->limit(100)->get()
            ->each(function (ResellerSale $s) use (&$n) {
                $before = $s->status;
                $this->applyJobOutcome($s, $s->job);
                $n += (int) ($s->status !== $before);
            });

        return $n;
    }

    public function syncOne(ResellerSale $sale): ResellerSale
    {
        if (in_array($sale->status, ['pending', 'needs_review'], true) && $sale->job) {
            return $this->applyJobOutcome($sale, $sale->job);
        }

        return $sale;
    }

    /** Admin decision for a needs_review sale after checking the server: refund it. */
    public function adminRefund(ResellerSale $sale, string $reason, int $actorId): ResellerSale
    {
        if (! in_array($sale->status, ['needs_review', 'pending'], true)) {
            throw new RuntimeException('فقط فروش‌های در انتظار یا نیازمند رسیدگی قابل بازپرداخت دستی هستند.');
        }
        $this->refund($sale, 'بازپرداخت توسط مدیر: '.$reason, $actorId);
        $this->audit->log('resellers.sale_refunded', $sale, 'بازپرداخت دستی فروش #'.$sale->id, ['cost_rial' => [$sale->cost_rial, null]], $reason);

        return $sale->fresh();
    }

    private function applyJobOutcome(ResellerSale $sale, ?ProvisioningJob $job): ResellerSale
    {
        if (! $job) {
            return $sale;
        }
        $job->refresh();
        if ($job->status === 'completed') {
            VpnAccount::query()->whereKey($job->vpn_account_id)->update(['reseller_id' => $sale->reseller_id]);
            $sale->fill(['status' => 'completed', 'vpn_account_id' => $job->vpn_account_id, 'error' => null])->save();
        } elseif (in_array($job->status, ['failed', 'compensated'], true)) {
            $this->refund($sale, (string) $job->last_error);
        } elseif ($job->status === 'needs_review') {
            $sale->fill(['status' => 'needs_review', 'error' => $job->last_error])->save();
        }

        return $sale;
    }

    private function refund(ResellerSale $sale, string $why, ?int $actorId = null): void
    {
        if ($sale->debit_entry_id && ! $sale->refund_entry_id) {
            $wallet = $this->wallets->walletFor('reseller', $sale->reseller_id);
            $entry = $this->wallets->post($wallet, (int) $sale->cost_rial, 'refund', "sale:{$sale->id}:refund",
                'بازگشت وجه فروش #'.$sale->id, mb_substr($why, 0, 450), $actorId, $sale);
            $sale->refund_entry_id = $entry->id;
        }
        $sale->fill(['status' => 'refunded', 'error' => mb_substr($why, 0, 500)])->save();
    }

    private function message(Throwable $e): string
    {
        return $e instanceof \App\Ibs\IbsException || $e instanceof IbsUnavailableException || $e instanceof \App\Ibs\IbsNotConfiguredException
            ? ErrorMessages::for($e) : $e->getMessage();
    }
}
