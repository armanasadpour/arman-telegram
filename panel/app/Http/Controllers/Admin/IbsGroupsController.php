<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IbsCharge;
use App\Models\IbsGroup;
use App\Models\VpnAccount;
use App\Services\Settings;
use Illuminate\View\View;

class IbsGroupsController extends Controller
{
    public function index(Settings $settings): View
    {
        $this->authorize('ibs_groups.view');

        return view('ibs.groups', [
            'groups' => IbsGroup::query()->with('charge')->orderByDesc('present')->orderBy('name')->get(),
            'charges' => IbsCharge::query()->orderByDesc('present')->orderBy('name')->get(),
            'accountCounts' => VpnAccount::query()->whereNull('ibs_deleted_at')->selectRaw('group_name, count(*) c')->groupBy('group_name')->pluck('c', 'group_name'),
            'syncedAt' => $settings->get('sync.catalog_at'),
        ]);
    }
}
