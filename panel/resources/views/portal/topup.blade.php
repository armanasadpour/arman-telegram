<x-layouts.app title="شارژ کیف پول">
  <x-hero title="شارژ کیف پول" art="wallet" text="مبلغ را به یکی از کارت‌های زیر واریز کنید، سپس مبلغ و عکس فیش را ثبت کنید. پس از تأیید مدیر، کیف پول شما شارژ می‌شود.">
    <div class="hero-balance">موجودی فعلی: <strong>{{ fa_money($wallet->balance) }}</strong></div>
  </x-hero>

  @if($pending)<div class="alert alert-warning">{{ fa($pending) }} درخواست شارژ منتظر تأیید مدیر دارید.</div>@endif

  @if($cards)
    <div class="bank-cards mb-16">
      @foreach($cards as $c)<x-bank-card :card="$c"/>@endforeach
    </div>
    <section class="card form-card mb-16">
      <h2>درخواست شارژ حساب</h2>
      <form method="post" action="{{ route('portal.topup.card') }}" enctype="multipart/form-data" data-confirm="درخواست شارژ ثبت شود؟">
        @csrf
        <div class="field"><label for="amount_toman">مبلغ واریزی (تومان)</label>
          <div class="input-ico"><input class="input ltr" id="amount_toman" name="amount_toman" data-money inputmode="numeric" required value="{{ old('amount_toman') }}" placeholder="مثلاً 500,000">
            <svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="7" rx="7" ry="3"/><path d="M5 7v5c0 1.7 3.1 3 7 3s7-1.3 7-3V7M5 12v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/></svg></div>
          @error('amount_toman')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="receipt">عکس فیش واریزی</label>
          <label class="upload-box" for="receipt">
            <img id="receipt-preview" alt="" hidden>
            <span class="upload-hint"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5M5 20h14"/></svg>انتخاب عکس فیش</span>
          </label>
          <input class="visually-hidden" type="file" id="receipt" name="receipt" accept="image/*" required data-preview="#receipt-preview">
          @error('receipt')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="note">توضیح (اختیاری)</label><input class="input" id="note" name="note" maxlength="200" value="{{ old('note') }}" placeholder="مثلاً ۴ رقم آخر کارت مبدأ"></div>
        <button class="btn btn-lg w-full" type="submit">ثبت درخواست شارژ</button>
      </form>
    </section>
  @else
    <div class="card mb-16"><div class="empty"><div>مدیر هنوز کارت بانکی برای واریز ثبت نکرده است.</div></div></div>
  @endif

  @if($methods)
    <details class="card mb-16">
      <summary><strong>پرداخت آنلاین</strong></summary>
      <form method="post" action="{{ route('portal.topup.store') }}" class="mt-8">
        @csrf
        <div class="form-grid">
          <div class="field"><label for="amount_online">مبلغ (تومان)</label><input class="input ltr" id="amount_online" name="amount_toman" data-money inputmode="numeric" required></div>
          <div class="field"><label for="method">روش پرداخت</label>
            <select class="input" id="method" name="method" required>@foreach($methods as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
          @if($networks)
            <div class="field"><label for="network">شبکه تتر (فقط برای USDT)</label>
              <select class="input" id="network" name="network"><option value="">—</option>@foreach($networks as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
          @endif
        </div>
        <button class="btn" type="submit">ادامه پرداخت</button>
      </form>
    </details>
  @endif

  @if($recent->isNotEmpty())
    <section class="card">
      <h2>درخواست‌ها و پرداخت‌های اخیر</h2>
      <div class="tx-list">
        @foreach($recent as $p)
          @php([$sl, $st] = \App\Models\Payment::STATUS[$p->status] ?? [$p->status, 'primary'])
          <div class="tx-row">
            <span class="tx-ico tone-{{ $st }}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="6" width="17" height="12" rx="2.5"/><path d="M3.5 10h17"/></svg></span>
            <span class="tx-main"><strong>{{ fa_money($p->amount_rial) }}</strong><small>{{ $p->methodLabel() }} · {{ jdate($p->created_at, 'Y/m/d H:i') }}</small></span>
            <span class="badge badge-{{ $st }}">{{ $sl }}</span>
          </div>
        @endforeach
      </div>
    </section>
  @endif
</x-layouts.app>
