<x-layouts.app title="داشبورد">
  <div class="page-head">
    <div>
      <h1>داشبورد</h1>
      <p class="muted">آخرین به‌روزرسانی: {{ jdate($generatedAt, 'Y/m/d H:i:s') }}</p>
    </div>
  </div>

  @if($protocols)
    <div class="proto-cards">
      @foreach($protocols as $key => $c)
        <a class="proto-card proto-{{ $key }}" href="{{ $c['url'] }}">
          <div class="proto-card-head">
            <span class="proto-card-title">{{ $c['title'] }}</span>
            <span class="proto-card-go" aria-hidden="true">‹</span>
          </div>
          <div class="proto-card-stats">
            <div class="pc-stat"><span class="pc-label">فعال</span><span class="pc-value">{{ fa($c['active']) }}</span></div>
            <div class="pc-stat"><span class="pc-label">آنلاین</span><span class="pc-value">@if($c['online'] > 0)<i class="pc-dot"></i>@endif{{ fa($c['online']) }}</span></div>
            <div class="pc-stat"><span class="pc-label">منقضی</span><span class="pc-value">{{ fa($c['expired']) }}</span></div>
            <div class="pc-stat"><span class="pc-label">مصرف دیتا</span><span class="pc-value pc-small">{{ fa_bytes($c['used']) }}</span>@if($c['today'] !== null)<span class="pc-sub" title="مصرف امروز">امروز {{ fa_bytes($c['today']) }}</span>@endif</div>
          </div>
        </a>
      @endforeach
    </div>
  @endif

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


</x-layouts.app>
