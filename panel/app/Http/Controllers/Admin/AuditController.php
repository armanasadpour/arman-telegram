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
        'system.upgraded' => 'به‌روزرسانی نسخه',
        'system.upgrade_failed' => 'به‌روزرسانی ناموفق',
        'audit.verified' => 'بررسی یکپارچگی گزارش',
        'settings.ibs_update' => 'تغییر تنظیمات اتصال به سرور',
        'settings.ibs_test' => 'تست اتصال به سرور',
        'accounts.create' => 'ساخت اکانت',
        'accounts.lock' => 'قفل اکانت',
        'accounts.unlock' => 'رفع قفل اکانت',
        'accounts.password' => 'تغییر رمز اکانت',
        'accounts.password_revealed' => 'مشاهده رمز اکانت',
        'accounts.renew' => 'تمدید اکانت',
        'accounts.add_volume' => 'افزایش حجم اکانت',
        'accounts.delete' => 'حذف اکانت',
        'accounts.import_all' => 'وارد کردن همه اکانت‌ها',
        'accounts.cleanup_started' => 'شروع حذف گروهی منقضی‌ها',
        'accounts.cleanup_finished' => 'پایان حذف گروهی منقضی‌ها',
        'accounts.cleanup_cancelled' => 'توقف حذف گروهی',
        'ibs_groups.create' => 'ساخت گروه در سرور',
        'bots.update' => 'تنظیمات ربات',
        'routers.create' => 'افزودن روتر میکروتیک',
        'routers.update' => 'ویرایش روتر میکروتیک',
        'routers.delete' => 'حذف روتر از پنل',
        'config_files.create' => 'افزودن فایل کانفیگ',
        'config_files.update' => 'ویرایش فایل کانفیگ',
        'config_files.relink' => 'لینک جدید فایل کانفیگ',
        'config_files.delete' => 'حذف فایل کانفیگ',
        'routers.pin' => 'تأیید گواهی روتر',
        'routers.protocol' => 'فعال/غیرفعال‌سازی پروتکل روی روتر',
        'settings.alerts' => 'تنظیمات اعلان‌ها',
        'payments.approve' => 'تأیید دستی پرداخت',
        'payments.reject' => 'رد پرداخت',
        'payments.settings' => 'تنظیمات درگاه‌های پرداخت',
        'payments.order_refund' => 'برگشت سفارش مشتری به کیف پول',
        'bots.texts' => 'متن‌های ربات',
        'bots.connect' => 'اتصال وبهوک ربات',
        'bots.rotate' => 'تعویض آدرس مخفی ربات',
        'bots.linked' => 'اتصال گفتگوی ربات به حساب پنل',
        'ibs_groups.update' => 'ویرایش گروه در سرور',
        'accounts.provision_review' => 'ساخت اکانت نیازمند رسیدگی',
        'accounts.provision_compensated' => 'لغو ساخت اکانت و پاک‌سازی',
        'accounts.provision_resolved' => 'بستن دستی ساخت اکانت',
        'accounts.package_applied' => 'اعمال بسته روی اکانت',
        'packages.create' => 'ایجاد بسته',
        'packages.update' => 'ویرایش بسته',
        'packages.delete' => 'حذف بسته',
        'packages.category_create' => 'ایجاد دسته بسته',
        'packages.category_delete' => 'حذف دسته بسته',
        'resellers.create' => 'ایجاد همکار',
        'resellers.update' => 'ویرایش همکار',
        'resellers.pricing' => 'تغییر قیمت همکار',
        'resellers.sale' => 'فروش همکار',
        'resellers.sale_refunded' => 'بازپرداخت دستی فروش همکار',
        'wallets.adjust' => 'شارژ/کسر دستی کیف پول',
        'wallets.reversal' => 'اصلاح ردیف دفتر کل',
        'discounts.create' => 'ایجاد تخفیف',
        'discounts.update' => 'ویرایش تخفیف',
        'discounts.delete' => 'حذف تخفیف',
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
