<x-layouts.app title="فروش‌های من">
  <div class="page-head"><h1>فروش‌های من</h1></div>
  <div class="card">
    @if($sales->isEmpty())<div class="empty"><div>هنوز فروشی ندارید.</div></div>@else
    <div class="table-wrap"><table class="table stack">
      <thead><tr><th>#</th><th>زمان</th><th>بسته</th><th>اکانت</th><th>خرید</th><th>فروش</th><th>سود</th><th>وضعیت</th></tr></thead>
      <tbody>
      @foreach($sales as $s)
        <tr><td data-label="#"><a href="{{ route('portal.sales.show', $s) }}">{{ fa((string) $s->id) }}</a></td><td data-label="زمان">{{ jdate($s->created_at) }}</td><td data-label="بسته">{{ $s->package?->name }}</td>
          <td data-label="اکانت"><bdi class="ltr">{{ $s->account?->username ?? '—' }}</bdi></td><td data-label="خرید">{{ fa_money($s->cost_rial) }}</td><td data-label="فروش">{{ fa_money($s->sale_price_rial) }}</td>
          <td data-label="سود">{{ fa_money($s->profit_rial) }}</td><td data-label="وضعیت">@php([$l, $t] = \App\Models\ResellerSale::STATUS[$s->status])<span class="badge badge-{{ $t }}">{{ $l }}</span></td></tr>
      @endforeach
      </tbody>
    </table></div>
    {{ $sales->links() }}
    @endif
  </div>
</x-layouts.app>
