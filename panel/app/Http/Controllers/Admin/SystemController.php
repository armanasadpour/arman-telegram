<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemStatus;
use Illuminate\View\View;

class SystemController extends Controller
{
    public function status(SystemStatus $status): View
    {
        $this->authorize('system.view');

        return view('system.status', ['checks' => $status->checks(), 'checkedAt' => now()]);
    }
}
