<x-layouts.app title="فروش #{{ $s->id }}">
  <div class="page-head"><h1>فروش #{{ fa((string) $s->id) }}</h1><a class="btn btn-secondary" href="{{ route('portal.sales') }}">همه فروش‌ها</a></div>
  @if(session('new_password') && $s->account)
    <div class="alert alert-success">مشخصات اتصال — نام کاربری: <strong><bdi class="ltr">{{ $s->account->username }}</bdi></strong> — رمز: <strong><bdi class="ltr">{{ session('new_password') }}</bdi></strong><br>رمز فقط همین یک بار نمایش داده می‌شود.</div>
  @endif
  <div class="card w-640">
    <dl class="kv">
      <dt>وضعیت</dt><dd>@php([$l, $t] = \App\Models\ResellerSale::STATUS[$s->status])<span class="badge badge-{{ $t }}">{{ $l }}</span></dd>
      <dt>زمان</dt><dd>{{ jdate($s->created_at, 'Y/m/d H:i') }}</dd>
      <dt>بسته</dt><dd>{{ $s->package?->name }}</dd>
      <dt>اکانت</dt><dd>@if($s->account)<a href="{{ route('portal.accounts.show', $s->account) }}"><bdi class="ltr">{{ $s->account->username }}</bdi></a>@else — @endif</dd>
      <dt>قیمت خرید شما</dt><dd>{{ fa_money($s->cost_rial) }}</dd>
      <dt>قیمت فروش به مشتری</dt><dd>{{ fa_money($s->sale_price_rial) }}</dd>
      <dt>سود</dt><dd>{{ fa_money($s->profit_rial) }}</dd>
      <dt>مشتری</dt><dd>{{ $s->customer_name ?: '—' }} {{ $s->customer_phone ? '('.$s->customer_phone.')' : '' }}</dd>
      @if($s->error)<dt>توضیح</dt><dd>{{ $s->error }}</dd>@endif
    </dl>
  </div>
</x-layouts.app>
