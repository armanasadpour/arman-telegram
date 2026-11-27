<x-layouts.app title="داشبورد">
  @php($di = [
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17" cy="9" r="2.6"/><path d="M16.5 14.2c2.9.3 5 2.4 5 5.3"/>',
    'wifi' => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.4a9.5 9.5 0 0 1 13 0M8.6 15.7a5 5 0 0 1 6.8 0"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/>',
    'pause' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
    'db' => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/>',
    'cart' => '<path d="M3 4h2.5l2.2 10.5h10.6L20.5 7H7"/><circle cx="9.5" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
    'cal' => '<rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 17h.01M12 17h.01"/>',
    'chart' => '<rect x="3.5" y="3.5" width="17" height="17" rx="4"/><path d="M8 16v-4M12 16V8M16 16v-6"/>',
  ])
  <div class="list-head dash-head">
    <span class="list-head-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['chart'] !!}</svg></span>
    <div><h1>داشبورد</h1><p>آخرین به‌روزرسانی: {{ jdate($generatedAt, 'Y/m/d') }} ؛ {{ jdate($generatedAt, 'H:i:s') }}</p></div>
  </div>

  @if($protocols)
    <div class="pdash mb-16">
      @foreach($protocols as $key => $c)
        <section class="pdash-card pd-{{ $key }}">
          <header class="pdash-head">
            <a class="pdash-id" href="{{ $c['url'] }}">
              <x-proto-logo :proto="$key"/>
              <span class="pdash-title"><strong>{{ $c['title'] }}</strong><small>پروتکل {{ $c['title'] }}</small></span>
            </a>
            @if($c['create'])<a class="pdash-new" href="{{ $c['create'] }}"><span>+</span> اکانت جدید</a>@endif
          </header>
          <a class="pdash-stats" href="{{ $c['url'] }}">
            <span class="pds"><svg class="ic-users" viewBox="0 0 24 24" aria-hidden="true">{!! $di['users'] !!}</svg><span class="pds-l">فعال</span><strong class="pds-v">{{ fa($c['active']) }}</strong></span>
            <span class="pds"><svg class="ic-wifi" viewBox="0 0 24 24" aria-hidden="true">{!! $di['wifi'] !!}</svg><span class="pds-l">آنلاین</span><strong class="pds-v">{{ fa($c['online']) }}<i class="pds-dot"></i></strong></span>
            <span class="pds"><svg class="ic-pause" viewBox="0 0 24 24" aria-hidden="true">{!! $di['pause'] !!}</svg><span class="pds-l">منقضی</span><strong class="pds-v">{{ fa($c['expired']) }}</strong></span>
            <span class="pds pds-wide"><svg class="ic-db" viewBox="0 0 24 24" aria-hidden="true">{!! $di['db'] !!}</svg><span class="pds-l">مصرف دیتا</span><strong class="pds-v pds-sm">{{ fa_bytes($c['used']) }}</strong>@if($c['today'] !== null)<small class="pds-sub">امروز {{ fa_bytes($c['today']) }}</small>@endif</span>
          </a>
        </section>
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
