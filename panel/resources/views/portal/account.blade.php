<x-layouts.app title="اکانت {{ $a->username }}">
  <div class="page-head">
    <div><h1><bdi class="ltr">{{ $a->username }}</bdi></h1><p class="muted">آخرین به‌روزرسانی: {{ $a->last_synced_at ? fa_ago($a->last_synced_at) : 'هرگز' }}</p></div>
    <div class="actions">
      <a class="btn" href="{{ route('portal.sell', ['account' => $a->id]) }}">تمدید / افزایش حجم</a>
      <a class="btn btn-secondary" href="{{ route('portal.accounts') }}">بازگشت</a>
    </div>
  </div>
  @php([$stLabel, $stTone] = \App\Models\VpnAccount::STATUS_LABELS[$a->status()] ?? [$a->status(), 'primary'])
  <div class="acct-top mb-16">
    @include('accounts._statuscard', ['label' => $stLabel, 'tone' => $stTone])
    @include('accounts._conncard', ['online' => (bool) $a->is_online, 'main' => $a->is_online ? 'آنلاین' : 'آفلاین', 'hint' => null,
      'hint2' => 'نشست‌های آنلاین: '.fa((int) $a->online_sessions), 'kickUrl' => null])
  </div>
  @include('accounts._meters', ['a' => $a, 'class' => 'mb-16'])
  <div class="card">
    <h2>سوابق فروش این اکانت</h2>
    @if($sales->isEmpty())<div class="empty"><div>—</div></div>@else
    <div class="table-wrap"><table class="table stack"><tbody>
      @foreach($sales as $s)<tr><td data-label="زمان">{{ jdate($s->created_at) }}</td><td data-label="بسته">{{ $s->package?->name }}</td><td data-label="سود">{{ fa_money($s->profit_rial) }}</td><td data-label="وضعیت">@php([$l, $t] = \App\Models\ResellerSale::STATUS[$s->status])<span class="badge badge-{{ $t }}">{{ $l }}</span></td></tr>@endforeach
    </tbody></table></div>
    @endif
  </div>
</x-layouts.app>
