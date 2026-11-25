<?php

namespace App\Services\Pricing;

use App\Models\Package;
use App\Models\ProvisioningJob;
use App\Models\VpnAccount;
use App\Services\AuditLogger;
use App\Services\Ibs\AccountActions;
use App\Services\Ibs\Provisioning;
use RuntimeException;

/**
 * Turns packages into IBSng operations. The price snapshot is recorded with the operation
 * (provisioning job spec / audit log); orders and payments arrive in later phases.
 */
class PackageApplier
{
    public function __construct(
        private PricingEngine $pricing,
        private Provisioning $provisioning,
        private AccountActions $actions,
        private AuditLogger $audit,
    ) {}

    /** @param array{username:?string,password:?string,comment:?string} $opts */
    public function createAccount(Package $package, string $jobKey, array $opts, ?int $actorId): ProvisioningJob
    {
        if ($package->type !== 'new' || ! $package->is_active || $package->protocol === 'mikrotik') {
            throw new RuntimeException('این بسته برای ساخت اکانت جدید قابل استفاده نیست.');
        }
        $quote = $this->pricing->quote($package);

        return $this->provisioning->create($jobKey, [
            'username' => $opts['username'] ?? null,
            'password' => $opts['password'] ?? null,
            'group' => (string) $package->ibs_group_name,
            'days' => $package->duration_days,
            'multi_login' => $package->multi_login,
            'volume_mb' => $package->volumeMb(),
            'comment' => trim((string) ($opts['comment'] ?? '')) ?: $package->name,
            'package' => $quote->snapshot(),
        ], $actorId);
    }

    /** Apply a renewal / top-up package to an existing account. */
    public function applyToAccount(Package $package, VpnAccount $account, string $reason): VpnAccount
    {
        if (! $package->is_active) {
            throw new RuntimeException('این بسته غیرفعال است.');
        }
        if ($why = PackageRules::applicability($package, $account)) {
            throw new RuntimeException($why);
        }
        $quote = $this->pricing->quote($package);
        $label = 'بسته «'.$package->name.'» — '.$reason;

        if ($package->duration_days) {
            [$account] = $this->actions->renew($account, (int) $package->duration_days, $label);
        }
        if ($package->hasVolume()) {
            $account = $this->actions->addVolume($account, (int) $package->volumeMb(), $label);
        }
        $this->audit->log('accounts.package_applied', $account, 'اعمال بسته «'.$package->name.'» روی «'.$account->username.'»',
            ['package' => [null, $quote->snapshot()]], $reason);

        return $account;
    }
}
