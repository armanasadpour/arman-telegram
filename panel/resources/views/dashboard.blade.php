<x-layouts.app title="داشبورد">
  <div class="page-head">
    <div>
      <h1>داشبورد</h1>
      <p class="muted">آخرین به‌روزرسانی: {{ jdate($generatedAt, 'Y/m/d H:i:s') }}</p>
    </div>
  </div>

  @if($problems)
    <div class="alert alert-warning">
      <strong>هشدارهای سیستم:</strong>
      <ul>
        @foreach($problems as $p)
          <li>{{ $p['label'] }}: {{ $p['detail'] }}</li>
        @endforeach
      </ul>
      <a href="{{ route('system.status') }}">مشاهده وضعیت سرویس‌ها</a>
    </div>
  @endif

  <div class="mb-16 grid stats">
    @can('staff.view')
      <a class="card stat" href="{{ route('staff.index') }}">
        <div class="label">کاربران فعال پنل</div>
        <div class="value">{{ fa($stats['staff_active']) }}</div>
        <div class="hint">از مجموع {{ fa($stats['staff_total']) }} کاربر</div>
      </a>
    @endcan
    @can('audit.view')
      <a class="card stat" href="{{ route('audit.index', ['action' => 'auth.login', 'days' => 1]) }}">
        <div class="label">ورودهای امروز</div>
        <div class="value">{{ fa($stats['logins_today']) }}</div>
        <div class="hint">از ساعت ۰۰:۰۰ امروز</div>
      </a>
      <a class="card stat" href="{{ route('audit.index', ['action' => 'auth.login_failed', 'days' => 1]) }}">
        <div class="label">ورودهای ناموفق ۲۴ ساعت</div>
        <div @class(['value', 'text-danger' => $stats['failed_24h'] > 0])>{{ fa($stats['failed_24h']) }}</div>
        <div class="hint">برای بررسی کلیک کنید</div>
      </a>
    @endcan
  </div>

  @can('audit.view')
    <div class="card">
      <div class="card-head">
        <h2>آخرین رویدادها</h2>
        <a href="{{ route('audit.index') }}" class="btn btn-secondary btn-sm">همه رویدادها</a>
      </div>
      @if($recent->isEmpty())
        <div class="empty"><x-icon name="inbox"/><div>هنوز رویدادی ثبت نشده است.</div></div>
      @else
        <div class="table-wrap">
          <table class="table stack">
            <thead><tr><th>زمان</th><th>کاربر</th><th>رویداد</th><th>توضیح</th></tr></thead>
            <tbody>
            @foreach($recent as $log)
              <tr>
                <td data-label="زمان">{{ jdate($log->created_at) }}</td>
                <td data-label="کاربر"><bdi>{{ $log->actor_label }}</bdi></td>
                <td data-label="رویداد"><a href="{{ route('audit.show', $log) }}">{{ \App\Http\Controllers\Admin\AuditController::LABELS[$log->action] ?? $log->action }}</a></td>
                <td data-label="توضیح">{{ $log->description }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  @endcan

  <div class="card">
    <h2>بخش‌های در راه</h2>
    <p class="m0 muted">آمار اکانت‌ها، فروش، پرداخت‌ها و همکاران پس از اتصال IBSng (فاز ۳) و ماژول‌های فروش (فازهای ۴ تا ۹) به این داشبورد اضافه می‌شوند. تا آن زمان هیچ عدد آزمایشی یا نمایشی اینجا نشان داده نمی‌شود.</p>
  </div>
</x-layouts.app>
