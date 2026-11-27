<x-layouts.app title="پنل همکار">
  @php($di = \App\Support\DashIcons::all())
  <div class="list-head dash-head">
    <span class="list-head-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['chart'] !!}</svg></span>
    <div><h1>{{ $me->name }}</h1><p>کد همکاری: <bdi class="ltr">{{ $me->code }}</bdi> · {{ jdate(now(), 'Y/m/d') }} ؛ {{ jdate(now(), 'H:i') }}</p></div>
  </div>
  @unless($me->isActive())<div class="alert alert-warning">حساب همکاری در حالت تعلیق است؛ فروش ممکن نیست.</div>@endunless

  @if($protocols)
    <div class="pdash mb-16">
      @foreach($protocols as $key => $c)
        @include('dashboard._proto-card', ['key' => $key, 'c' => $c])
      @endforeach
    </div>
  @endif

  <div class="fin-grid mb-16">
    <a class="fin-card fin-violet" href="{{ route('portal.ledger') }}">
      <span class="fin-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['wallet'] !!}</svg></span>
      <span class="fin-l">موجودی کیف پول</span><strong class="fin-v">{{ fa_money($wallet->balance) }}</strong>
      <small class="fin-h">شارژ از منوی کیف پول</small>
    </a>
    <a class="fin-card fin-green" href="{{ route('portal.sales') }}">
      <span class="fin-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['cart'] !!}</svg></span>
      <span class="fin-l">فروش این ماه</span><strong class="fin-v">{{ fa($stats['month_sales']) }}</strong>
      <small class="fin-h">خرید: {{ fa_money($stats['month_spent']) }}</small>
    </a>
    <div class="fin-card fin-amber fin-wide">
      <span class="fin-l"><span class="fin-ico fin-ico-in"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $di['profit'] !!}</svg></span>سود این ماه</span>
      <strong class="fin-v">{{ fa_money($stats['month_profit']) }}</strong>
      <small class="fin-h">{{ fa($stats['accounts']) }} اکانت · {{ fa($stats['online']) }} آنلاین — سود = قیمت فروش − قیمت خرید</small>
      <span class="fin-bars" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
    </div>
  </div>

  <div class="charts mb-16">
    <div class="card"><h2>سود ۳۰ روز اخیر</h2><x-chart :values="$daily['profit']" money kind="success"/></div>
    <div class="card"><h2>تعداد فروش ۳۰ روز اخیر</h2><x-chart :values="$daily['sales']"/></div>
  </div>
  <div class="card">
    <h2>خروجی اکسل (۹۰ روز اخیر)</h2>
    <div class="actions">
      <a class="btn btn-secondary" href="{{ route('portal.export', 'sales') }}">فروش‌های من</a>
      <a class="btn btn-secondary" href="{{ route('portal.export', 'ledger') }}">تراکنش‌های کیف پول</a>
      <a class="btn btn-secondary" href="{{ route('portal.export', 'accounts') }}">اکانت‌های من</a>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2>آخرین فروش‌ها</h2><a class="btn btn-secondary btn-sm" href="{{ route('portal.sales') }}">همه</a></div>
    @if($recent->isEmpty())<div class="empty"><div>هنوز فروشی ندارید.</div></div>@else
      <div class="table-wrap"><table class="table stack"><tbody>
        @foreach($recent as $s)
          <tr><td data-label="زمان">{{ jdate($s->created_at) }}</td><td data-label="بسته"><a href="{{ route('portal.sales.show', $s) }}">{{ $s->package?->name }}</a></td>
            <td data-label="سود">{{ fa_money($s->profit_rial) }}</td><td data-label="وضعیت">@php([$l, $t] = \App\Models\ResellerSale::STATUS[$s->status])<span class="badge badge-{{ $t }}">{{ $l }}</span></td></tr>
        @endforeach
      </tbody></table></div>
    @endif
  </div>
</x-layouts.app>
