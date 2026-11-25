<x-layouts.app title="پرداخت #{{ $p->id }}">
  <div class="page-head"><h1>پرداخت #{{ fa((string) $p->id) }}</h1><a class="btn btn-secondary" href="{{ route('payments.index') }}">بازگشت</a></div>
  <div class="card">
    <dl class="kv">
      <dt>پرداخت‌کننده</dt><dd>{{ $p->payerLabel() }}</dd>
      <dt>روش</dt><dd>{{ $p->methodLabel() }}</dd>
      <dt>مبلغ</dt><dd>{{ fa_money($p->amount_rial) }}</dd>
      <dt>وضعیت</dt><dd><span class="badge badge-{{ \App\Models\Payment::STATUS[$p->status][1] }}">{{ \App\Models\Payment::STATUS[$p->status][0] }}</span></dd>
      <dt>زمان ایجاد</dt><dd>{{ jdate($p->created_at) }}</dd>
      @if($p->paid_at)<dt>زمان پرداخت</dt><dd>{{ jdate($p->paid_at) }}</dd>@endif
      @if($p->gateway_ref)<dt>شماره مرجع</dt><dd><bdi class="ltr break">{{ $p->gateway_ref }}</bdi></dd>@endif
      @if($p->card_pan)<dt>کارت</dt><dd><bdi class="ltr">{{ $p->card_pan }}</bdi></dd>@endif
      @if($p->method === 'usdt')
        <dt>شبکه / مبلغ تتر</dt><dd>{{ \App\Models\Payment::NETWORKS[$p->usdt_network] ?? '' }} — <bdi class="ltr">{{ rtrim(rtrim((string) $p->usdt_amount, '0'), '.') }} USDT</bdi></dd>
        @if($p->tx_hash)<dt>هش تراکنش</dt><dd><bdi class="ltr break small">{{ $p->tx_hash }}</bdi></dd>@endif
      @endif
      @if($p->payer_note)<dt>اطلاعات واریز</dt><dd>{{ $p->payer_note }}</dd>@endif
      @if($p->error)<dt>پیام</dt><dd>{{ $p->error }}</dd>@endif
      @if($p->reviewer)<dt>بررسی‌کننده</dt><dd>{{ $p->reviewer->name }} — {{ $p->review_reason }}</dd>@endif
      @if($p->order)<dt>سفارش</dt><dd>#{{ fa((string) $p->order->id) }} — {{ $p->order->package?->name }} — {{ \App\Models\CustomerOrder::STATUS[$p->order->status][0] }}</dd>@endif
    </dl>
    @if($p->receipt_path)
      <p><a href="{{ route('payments.receipt', $p) }}" target="_blank" rel="noopener">مشاهده تصویر رسید</a></p>
    @endif
    @if(($p->method === 'usdt' && $p->tx_hash && ! $p->isFinal()) || ($p->isOnline() && $p->status === 'redirected'))
      <form method="post" action="{{ route('payments.recheck', $p) }}" class="mb-12">@csrf<button class="btn btn-secondary" type="submit">بررسی دوباره</button></form>
    @endif
  </div>
  @can('payments.sensitive')
    @if(! $p->isOnline() && $p->status === 'review')
      <div class="card">
        <h2>بررسی</h2>
        <p class="muted small">{{ $p->method === 'card' ? 'واریز را در حساب بانکی خود با شماره پیگیری و مبلغ مقایسه کنید.' : 'تراکنش را در اکسپلورر شبکه بررسی کنید: مقصد، توکن USDT و مبلغ.' }} تأیید، کیف پول را یک‌بار و غیرقابل‌برگشت شارژ می‌کند.</p>
        <form method="post" action="{{ route('payments.approve', $p) }}" class="mb-12" data-confirm="مبلغ {{ fa_money($p->amount_rial) }} به کیف پول اضافه شود؟">
          @csrf
          <div class="form-grid">
            <div class="field"><label for="ref">شماره مرجع بانک (اختیاری)</label><input class="input ltr" id="ref" name="ref" maxlength="100"></div>
            <div class="field"><label for="reason_ok">توضیح</label><input class="input" id="reason_ok" name="reason" required minlength="3" maxlength="300" placeholder="مثلاً در صورت‌حساب بانک دیده شد"></div>
          </div>
          <button class="btn" type="submit">تأیید و شارژ</button>
        </form>
        <form method="post" action="{{ route('payments.reject', $p) }}" data-confirm="این پرداخت رد شود؟">
          @csrf
          <div class="field"><label for="reason_no">دلیل رد</label><input class="input" id="reason_no" name="reason" required minlength="3" maxlength="300"></div>
          <button class="btn btn-danger" type="submit">رد پرداخت</button>
        </form>
      </div>
    @endif
  @endcan
</x-layouts.app>
