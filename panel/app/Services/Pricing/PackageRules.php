<?php

namespace App\Services\Pricing;

use App\Models\IbsGroup;
use App\Models\MtGroup;
use App\Models\Package;
use App\Models\VpnAccount;

/** Consistency rules between a package and real IBSng data. Returns Persian error messages. */
class PackageRules
{
    /** @param array<string,mixed> $d validated form data @return array<string,string> field => error */
    public static function validate(array $d): array
    {
        $e = [];
        $type = $d['type'] ?? null;
        $hasVolume = isset($d['volume_gb']) && (float) $d['volume_gb'] > 0;

        if (($d['protocol'] ?? 'openvpn') === 'mikrotik') {
            if ($type === 'new') {
                $g = MtGroup::query()->find($d['mt_group_id'] ?? 0);
                if (! $g) {
                    $e['mt_group_id'] = 'گروه میکروتیک را انتخاب کنید.';
                }
                if (empty($d['duration_days']) && ! $hasVolume) {
                    $e['duration_days'] = 'بسته باید دست‌کم مدت یا حجم داشته باشد.';
                }
            } elseif ($type === 'renewal' && empty($d['duration_days'])) {
                $e['duration_days'] = 'بسته تمدید باید مدت (روز) داشته باشد.';
            } elseif ($type === 'topup') {
                if (! $hasVolume) {
                    $e['volume_gb'] = 'بسته افزایش حجم باید حجم داشته باشد.';
                }
                if (! empty($d['duration_days'])) {
                    $e['duration_days'] = 'بسته افزایش حجم مدت ندارد؛ برای افزودن روز از بسته تمدید استفاده کنید.';
                }
            }

            return $e;
        }
        if ($type === 'new') {
            if (empty($d['duration_days'])) {
                $e['duration_days'] = 'برای بسته اکانت جدید مدت اعتبار لازم است.';
            }
            $g = IbsGroup::query()->with('charge')->where('name', $d['ibs_group_name'] ?? '')->where('present', true)->first();
            if (! $g) {
                $e['ibs_group_name'] = 'گروه سرور انتخاب‌شده وجود ندارد (ابتدا گروه‌ها را همگام‌سازی کنید).';
            } elseif ($g->charge?->isVolume() && ! $hasVolume) {
                $e['volume_gb'] = 'تعرفه گروه «'.$g->name.'» حجمی است؛ حجم بسته را وارد کنید.';
            } elseif (! $g->charge?->isVolume() && $hasVolume) {
                $e['volume_gb'] = 'تعرفه گروه «'.$g->name.'» حجمی نیست؛ سرور حجم را محدود نمی‌کند. حجم را خالی بگذارید یا گروهی با تعرفه حجمی انتخاب کنید.';
            }
        } elseif ($type === 'renewal') {
            if (empty($d['duration_days'])) {
                $e['duration_days'] = 'بسته تمدید باید مدت (روز) داشته باشد.';
            }
        } elseif ($type === 'topup') {
            if (! $hasVolume) {
                $e['volume_gb'] = 'بسته افزایش حجم باید حجم داشته باشد.';
            }
            if (! empty($d['duration_days'])) {
                $e['duration_days'] = 'بسته افزایش حجم مدت ندارد؛ برای افزودن روز از بسته تمدید استفاده کنید.';
            }
        }

        return $e;
    }

    /** Can this renewal/top-up package be applied to this account? null = yes, else reason. */
    public static function applicability(Package $p, VpnAccount $a): ?string
    {
        if (! in_array($p->type, ['renewal', 'topup'], true)) {
            return 'فقط بسته‌های تمدید و افزایش حجم روی اکانت موجود اعمال می‌شوند.';
        }
        if ($p->protocol === 'mikrotik') {
            return 'این بسته مخصوص اکانت‌های میکروتیک (L2TP/WireGuard) است.';
        }
        if ($p->hasVolume() && ! $a->isVolumeLimited()) {
            return 'این بسته حجم دارد ولی تعرفه این اکانت حجمی نیست.';
        }

        return null;
    }
}
