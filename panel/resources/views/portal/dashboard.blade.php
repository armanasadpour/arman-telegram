<x-layouts.app title="پنل همکار">
  <div class="page-head">
    <div><h1>{{ $me->name }}</h1><p class="muted">کد همکاری: <bdi class="ltr">{{ $me->code }}</bdi>@unless($me->isActive()) — <span class="badge badge-warning">حساب همکاری در حالت تعلیق است؛ فروش ممکن نیست</span>@endunless</p></div>
    @if($me->isActive())<a class="btn" href="{{ route('portal.sell') }}">فروش اکانت جدید</a>@endif
  </div>
  <div class="grid stats mb-16">
    <a class="card stat" href="{{ route('portal.ledger') }}"><div class="label">موجودی کیف پول</div><div class="value small">{{ fa_money($wallet->balance) }}</div><div class="hint">برای شارژ با مدیر تماس بگیرید</div></a>
    <a class="card stat" href="{{ route('portal.accounts') }}"><div class="label">اکانت‌های من</div><div class="value">{{ fa($stats['accounts']) }}</div><div class="hint">{{ fa($stats['online']) }} آنلاین</div></a>
    <a class="card stat" href="{{ route('portal.sales') }}"><div class="label">فروش این ماه</div><div class="value">{{ fa($stats['month_sales']) }}</div><div class="hint">خرید: {{ fa_money($stats['month_spent']) }}</div></a>
    <div class="card stat"><div class="label">سود این ماه</div><div class="value small">{{ fa_money($stats['month_profit']) }}</div><div class="hint">قیمت فروش − قیمت خرید</div></div>
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
