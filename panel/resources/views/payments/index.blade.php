<x-layouts.app title="پرداخت‌ها">
  <div class="page-head">
    <div><h1>پرداخت‌ها</h1><p class="muted">امروز: {{ fa_money($today) }} — ۳۰ روز اخیر: {{ fa_money($month) }}</p></div>
    @can('payments.sensitive')<a class="btn btn-secondary" href="{{ route('payments.settings') }}">تنظیمات درگاه‌ها</a>@endcan
  </div>
  @if($review)
    <div class="alert alert-warning">{{ fa($review) }} پرداخت کارت‌به‌کارت/تتر منتظر بررسی است. <a href="{{ route('payments.index', ['status' => 'review']) }}">مشاهده</a></div>
  @endif
  @if($orders->isNotEmpty())
    <div class="card">
      <h2>سفارش‌های مشتری در حال انجام یا نیازمند رسیدگی</h2>
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>#</th><th>بسته</th><th>مشتری</th><th>وضعیت</th><th>مبلغ</th><th></th></tr></thead>
        <tbody>
        @foreach($orders as $o)
          <tr>
            <td data-label="#">{{ fa((string) $o->id) }}</td>
            <td data-label="بسته">{{ $o->package?->name }} @if($o->account)— <bdi>{{ $o->account->username }}</bdi>@endif</td>
            <td data-label="مشتری">{{ $o->botUser?->first_name ?? $o->botUser?->chat_id }}</td>
            <td data-label="وضعیت"><span class="badge badge-{{ \App\Models\CustomerOrder::STATUS[$o->status][1] }}">{{ \App\Models\CustomerOrder::STATUS[$o->status][0] }}</span> <span class="muted small">{{ $o->error }}</span></td>
            <td data-label="مبلغ">{{ fa_money($o->price_rial) }}</td>
            <td data-label="">
              @can('payments.sensitive')
                <form method="post" action="{{ route('orders.refund', $o) }}" class="actions" data-confirm="مبلغ این سفارش به کیف پول مشتری برگردد؟ (فقط وقتی در سرور اکانتی ساخته/تمدید نشده)">@csrf
                  <button class="btn btn-secondary btn-sm" type="submit">برگشت به کیف پول</button></form>
              @endcan
            </td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </div>
  @endif
  <div class="card">
    <form method="get" class="form-grid mb-12">
      <div class="field"><label for="status">وضعیت</label><select class="input" id="status" name="status"><option value="">همه</option>@foreach(\App\Models\Payment::STATUS as $k => [$l])<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label for="method">روش</label><select class="input" id="method" name="method"><option value="">همه</option>@foreach(\App\Models\Payment::METHODS as $k => $l)<option value="{{ $k }}" @selected(request('method') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="actions"><button class="btn" type="submit">اعمال</button></div>
    </form>
    @if($payments->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>پرداختی ثبت نشده است.</div></div>
    @else
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>#</th><th>زمان</th><th>پرداخت‌کننده</th><th>روش</th><th>مبلغ</th><th>وضعیت</th></tr></thead>
        <tbody>
        @foreach($payments as $p)
          <tr>
            <td data-label="#"><a href="{{ route('payments.show', $p) }}">{{ fa((string) $p->id) }}</a></td>
            <td data-label="زمان">{{ jdate($p->created_at) }}</td>
            <td data-label="پرداخت‌کننده">{{ $p->payerLabel() }}</td>
            <td data-label="روش">{{ $p->methodLabel() }}</td>
            <td data-label="مبلغ">{{ fa_money($p->amount_rial) }}</td>
            <td data-label="وضعیت"><span class="badge badge-{{ \App\Models\Payment::STATUS[$p->status][1] }}">{{ \App\Models\Payment::STATUS[$p->status][0] }}</span></td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
      {{ $payments->links() }}
    @endif
  </div>
</x-layouts.app>
