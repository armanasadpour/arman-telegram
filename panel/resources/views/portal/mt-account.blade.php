@php($lbl = $a->statusLabel())
<x-layouts.app :title="'اکانت '.$a->username">
  <div class="page-head">
    <div><h1><bdi class="ltr">{{ $a->username }}</bdi> <span class="badge badge-{{ $lbl[1] }}">{{ $lbl[0] }}</span></h1>
      <p class="muted">{{ $a->protocolLabel() }} — {{ $a->is_online ? 'آنلاین' : ($a->last_seen_at ? 'آخرین اتصال '.fa_ago($a->last_seen_at) : 'هنوز وصل نشده') }}</p></div>
    <div class="actions">
      @if(! in_array($a->status, ['pending', 'failed'], true))<a class="btn" href="{{ route('portal.sell', ['mt' => $a->id]) }}">تمدید / افزایش حجم</a>@endif
      <a class="btn btn-secondary" href="{{ route('portal.accounts') }}">بازگشت</a>
    </div>
  </div>
  @if($a->status === 'pending')<div class="alert alert-warning">اکانت در حال ساخت روی سرور است؛ چند دقیقه دیگر دوباره سر بزنید.</div>@endif
  @include('mt.accounts._meters', ['a' => $a, 'class' => 'mb-16'])
  @if(! in_array($a->status, ['pending', 'failed'], true))@include('mt.accounts._connect', ['a' => $a])@endif
  <div class="card w-640">
    <h2>سوابق فروش</h2>
    @forelse($sales as $s)
      <div class="kv-row"><a href="{{ route('portal.sales.show', $s) }}">#{{ fa((string) $s->id) }}</a> — {{ $s->package?->name }} — {{ jdate($s->created_at, 'Y/m/d') }} — {{ fa_money($s->sale_price_rial) }}</div>
    @empty<div class="muted">—</div>@endforelse
  </div>
</x-layouts.app>
