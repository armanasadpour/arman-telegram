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

  @php($attn = array_values(array_filter([
    $attention['payments_review'] && auth()->user()->can('payments.view') ? ['url' => route('payments.index', ['status' => 'review']), 'text' => fa($attention['payments_review']).' پرداخت منتظر بررسی'] : null,
    $attention['sales_review'] && auth()->user()->can('resellers.view') ? ['url' => route('resellers.index'), 'text' => fa($attention['sales_review']).' فروش همکار'] : null,
    $attention['orders_review'] && auth()->user()->can('payments.view') ? ['url' => route('payments.index'), 'text' => fa($attention['orders_review']).' سفارش مشتری'] : null,
    $attention['jobs'] && auth()->user()->can('accounts.view') ? ['url' => route('accounts.jobs'), 'text' => fa($attention['jobs']).' ساخت نیمه‌کاره'] : null,
  ])))
  @if($attn)
    <div class="alert alert-warning">
      <strong>نیازمند رسیدگی:</strong>
      @foreach($attn as $i => $x)@if($i > 0) · @endif<a href="{{ $x['url'] }}">{{ $x['text'] }}</a>@endforeach
    </div>
  @endif

  @if($finance)
    @php($sum = $finance['sum'])
    <div class="kpis mb-16">
      <a class="kpi" href="{{ route('reports.index', ['from' => jdate(now(), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')]) }}"><div class="label">فروش امروز</div><div class="value">{{ fa_money($sum($finance['today'])) }}</div><div class="hint">{{ fa($finance['today']['sales_count'] + $finance['today']['orders_count']) }} فروش — پرداخت {{ fa_money($finance['today']['payments_rial']) }}</div></a>
      <a class="kpi" href="{{ route('reports.index', ['from' => jdate(now()->subDays(6), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')]) }}"><div class="label">۷ روز اخیر</div><div class="value">{{ fa_money($sum($finance['week'])) }}</div><div class="hint">{{ fa($finance['week']['sales_count'] + $finance['week']['orders_count']) }} فروش</div></a>
      <a class="kpi" href="{{ route('reports.index') }}"><div class="label">۳۰ روز اخیر</div><div class="value">{{ fa_money($sum($finance['month'])) }}</div><div class="hint">{{ fa($finance['month']['sales_count'] + $finance['month']['orders_count']) }} فروش — {{ fa($finance['month']['new_accounts']) }} اکانت جدید</div></a>
    </div>
    <div class="charts mb-16">
      <div class="card"><h2>فروش ۱۴ روز اخیر</h2><x-chart :values="$finance['daily']['revenue']" money kind="success"/></div>
      <div class="card"><h2>تعداد فروش ۱۴ روز اخیر</h2><x-chart :values="$finance['daily']['count']"/></div>
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

</x-layouts.app>
