<x-layouts.guest title="پرداخت">
  <h1 class="mb-8">پرداخت #{{ fa((string) $p->id) }}</h1>
  <p class="muted">{{ $p->methodLabel() }} — {{ $p->payerLabel() }}</p>
  <div class="stat-line mb-12"><span>مبلغ</span> <strong>{{ fa_money($p->amount_rial) }}</strong></div>
  <div class="mb-12">وضعیت: <span class="badge badge-{{ \App\Models\Payment::STATUS[$p->status][1] ?? 'primary' }}">{{ \App\Models\Payment::STATUS[$p->status][0] ?? $p->status }}</span></div>

  @if($p->status === 'paid')
    <div class="alert alert-success">پرداخت تأیید شد{{ $p->gateway_ref ? ' — کد پیگیری: '.$p->gateway_ref : '' }}. {{ $p->bot_user_id ? 'نتیجه در ربات برای شما ارسال شد؛ می‌توانید به ربات برگردید.' : 'کیف پول شما شارژ شد.' }}</div>
    @if(! $p->bot_user_id)<a class="btn" href="{{ url('/r/ledger') }}">مشاهده کیف پول</a>@endif
  @elseif(in_array($p->status, ['failed', 'rejected', 'expired'], true))
    <div class="alert alert-danger">{{ $p->error ?? ($p->review_reason ? 'دلیل: '.$p->review_reason : 'پرداخت انجام نشد.') }} هیچ مبلغی از حساب شما کسر نشده است؛ اگر کسر شده، بانک طی ۷۲ ساعت آن را برمی‌گرداند.</div>
  @elseif($p->isOnline())
    @if(($returned ?? false) && $p->error)
      <div class="alert alert-warning">{{ $p->error }} تأیید پرداخت خودکار دوباره انجام می‌شود؛ نیازی به پرداخت مجدد نیست.</div>
    @else
      <form method="post" action="{{ route('pay.go', $p->uuid) }}">@csrf<button class="btn w-full" type="submit">انتقال به درگاه {{ $p->methodLabel() }}</button></form>
    @endif
  @elseif($p->method === 'card')
    @if($p->status === 'review')
      <div class="alert alert-warning">اطلاعات واریز ثبت شده و منتظر بررسی مدیر است. {{ $p->payer_note }}</div>
    @else
      <div class="card-inset mb-12">
        <p>مبلغ <strong>{{ fa_money($p->amount_rial) }}</strong> را دقیقاً به یکی از کارت‌های زیر واریز کنید:</p>
        @foreach($cards as $c)
          <div class="mb-8"><bdi class="ltr"><strong>{{ trim(chunk_split($c['number'], 4, ' ')) }}</strong></bdi><br><span class="muted small">{{ $c['holder'] ?? '' }} — {{ $c['bank'] ?? '' }}</span></div>
        @endforeach
      </div>
      <form method="post" action="{{ route('pay.card', $p->uuid) }}" enctype="multipart/form-data">
        @csrf
        <div class="field"><label for="tracking">شماره پیگیری / مرجع</label><input class="input ltr" id="tracking" name="tracking" required maxlength="40" value="{{ old('tracking') }}"></div>
        <div class="field"><label for="last4">۴ رقم آخر کارت مبدأ</label><input class="input ltr" id="last4" name="last4" required maxlength="4" inputmode="numeric" value="{{ old('last4') }}"></div>
        <div class="field"><label for="paid_at">زمان واریز (اختیاری)</label><input class="input" id="paid_at" name="paid_at" maxlength="40" placeholder="مثلاً ۱۴۰۵/۰۹/۰۵ ساعت ۱۴:۲۰" value="{{ old('paid_at') }}"></div>
        <div class="field"><label for="receipt">تصویر رسید (اختیاری)</label><input class="input" type="file" id="receipt" name="receipt" accept="image/*"></div>
        <button class="btn w-full" type="submit">ثبت اطلاعات واریز</button>
      </form>
    @endif
  @elseif($p->method === 'usdt')
    <div class="card-inset mb-12">
      <p>دقیقاً <strong><bdi class="ltr">{{ rtrim(rtrim((string) $p->usdt_amount, '0'), '.') }} USDT</bdi></strong> در شبکه <strong>{{ \App\Models\Payment::NETWORKS[$p->usdt_network] ?? $p->usdt_network }}</strong> به این آدرس بفرستید:</p>
      <p><bdi class="ltr break"><strong>{{ $address }}</strong></bdi></p>
      <p class="muted small">ارسال در شبکه دیگر یا توکن دیگر قابل بازگشت نیست. کارمزد شبکه با شماست؛ مبلغ دریافتی نباید کمتر از مقدار بالا باشد.</p>
    </div>
    @if($p->tx_hash)
      <div class="alert alert-warning">هش ثبت شد: <bdi class="ltr break small">{{ $p->tx_hash }}</bdi><br>{{ $p->error ?? 'در انتظار تأیید شبکه' }}</div>
    @else
      <form method="post" action="{{ route('pay.usdt', $p->uuid) }}">
        @csrf
        <div class="field"><label for="tx_hash">هش تراکنش (TxID)</label><input class="input ltr" id="tx_hash" name="tx_hash" required maxlength="100" value="{{ old('tx_hash') }}" autocomplete="off" spellcheck="false"></div>
        <button class="btn w-full" type="submit">ثبت و بررسی تراکنش</button>
      </form>
    @endif
  @endif
</x-layouts.guest>
