<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController extends Controller
{
    /** Persian labels for known actions. Unknown actions are shown raw. */
    public const LABELS = [
        'auth.login' => 'ورود',
        'auth.logout' => 'خروج',
        'auth.login_failed' => 'ورود ناموفق',
        'auth.login_blocked' => 'تلاش ورود حساب غیرفعال',
        'auth.password_changed' => 'تغییر رمز (توسط خود کاربر)',
        'auth.password_confirmed' => 'تأیید مجدد رمز',
        'staff.create' => 'ایجاد کاربر پنل',
        'staff.create_super_admin' => 'ایجاد مدیرکل',
        'staff.update' => 'ویرایش کاربر پنل',
        'staff.activate' => 'فعال‌سازی کاربر',
        'staff.deactivate' => 'غیرفعال‌سازی کاربر',
        'staff.password_reset' => 'تعیین رمز توسط مدیر',
        'staff.delete' => 'حذف کاربر پنل',
        'roles.create' => 'ایجاد نقش',
        'roles.update' => 'ویرایش نقش',
        'roles.delete' => 'حذف نقش',
        'settings.update' => 'تغییر تنظیمات',
        'system.installed' => 'نصب سامانه',
        'audit.verified' => 'بررسی یکپارچگی گزارش',
    ];

    public function index(Request $request): View
    {
        $this->authorize('audit.view');
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'in:1,7,30,90,365'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', $a))
            ->when($filters['user_id'] ?? null, fn ($q, $u) => $q->where('user_id', $u))
            ->when($filters['days'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', now()->subDays((int) $d)))
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('description', 'like', "%$t%")
                ->orWhere('actor_label', 'like', "%$t%")->orWhere('ip', 'like', "%$t%")))
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');
        $users = User::query()->orderBy('name')->get(['id', 'name', 'username']);

        return view('audit.index', ['logs' => $logs, 'actions' => $actions, 'users' => $users, 'filters' => $filters, 'labels' => self::LABELS]);
    }

    public function show(AuditLog $log): View
    {
        $this->authorize('audit.view');

        return view('audit.show', ['log' => $log, 'labels' => self::LABELS]);
    }

    /** Recompute the hash chain over all records. */
    public function verify(AuditLogger $audit): RedirectResponse
    {
        $this->authorize('audit.view');
        $broken = $audit->verifyChain();
        $audit->log('audit.verified', null, $broken === null ? 'زنجیره سالم است' : "زنجیره از رکورد #$broken به بعد خراب است");

        return $broken === null
            ? back()->with('success', 'یکپارچگی گزارش رویدادها تأیید شد؛ هیچ رکوردی تغییر یا حذف نشده است.')
            : back()->with('error', 'هشدار: رکورد شماره '.fa((string) $broken).' یا رکورد قبل از آن تغییر کرده یا حذف شده است.');
    }
}
