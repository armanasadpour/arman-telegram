<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\SystemStatus;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, SystemStatus $status): View
    {
        $this->authorize('dashboard.view');
        $user = $request->user();
        $todayStart = now(config('app.display_timezone'))->startOfDay()->utc();

        $stats = [
            'staff_active' => User::query()->where('is_active', true)->count(),
            'staff_total' => User::query()->count(),
            'logins_today' => AuditLog::query()->where('action', 'auth.login')->where('created_at', '>=', $todayStart)->count(),
            'failed_24h' => AuditLog::query()->where('action', 'auth.login_failed')->where('created_at', '>=', now()->subDay())->count(),
        ];
        $problems = $user->can('system.view')
            ? array_values(array_filter($status->checks(), fn ($c) => $c['status'] !== 'ok'))
            : [];
        $recent = $user->can('audit.view')
            ? AuditLog::query()->orderByDesc('id')->limit(8)->get()
            : collect();

        return view('dashboard', compact('stats', 'problems', 'recent') + ['generatedAt' => now()]);
    }
}
