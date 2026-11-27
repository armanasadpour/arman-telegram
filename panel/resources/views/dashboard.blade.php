<x-layouts.app title="داشبورد">
  @php($di = \App\Support\DashIcons::all())
  <div class="list-head dash-head">
    <span class="list-head-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['chart'] !!}</svg></span>
    <div><h1>داشبورد</h1><p>آخرین به‌روزرسانی: {{ jdate($generatedAt, 'Y/m/d') }} ؛ {{ jdate($generatedAt, 'H:i:s') }}</p></div>
  </div>

  @if($protocols)
    <div class="pdash mb-16">
      @foreach($protocols as $key => $c)
        @include('dashboard._proto-card', ['key' => $key, 'c' => $c])
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
    <div class="fin-grid mb-16">
      <a class="fin-card fin-violet" href="{{ route('reports.index', ['from' => jdate(now(), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')]) }}">
        <span class="fin-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['cart'] !!}</svg></span>
        <span class="fin-l">فروش امروز</span><strong class="fin-v">{{ fa_money($sum($finance['today'])) }}</strong>
        <small class="fin-h">{{ fa($finance['today']['sales_count'] + $finance['today']['orders_count']) }} فروش – {{ fa($finance['today']['payments_count'] ?? 0) }} پرداخت</small>
      </a>
      <a class="fin-card fin-green" href="{{ route('reports.index', ['from' => jdate(now()->subDays(6), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')]) }}">
        <span class="fin-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['cal'] !!}</svg></span>
        <span class="fin-l">۷ روز اخیر</span><strong class="fin-v">{{ fa_money($sum($finance['week'])) }}</strong>
        <small class="fin-h">{{ fa($finance['week']['sales_count'] + $finance['week']['orders_count']) }} فروش</small>
      </a>
      <a class="fin-card fin-amber fin-wide" href="{{ route('reports.index') }}">
        <span class="fin-l"><span class="fin-ico fin-ico-in"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['cal'] !!}</svg></span>۳۰ روز اخیر</span>
        <strong class="fin-v">{{ fa_money($sum($finance['month'])) }}</strong>
        <small class="fin-h">{{ fa($finance['month']['sales_count'] + $finance['month']['orders_count']) }} فروش – {{ fa($finance['month']['new_accounts']) }} اکانت جدید</small>
        <span class="fin-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
      </a>
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
