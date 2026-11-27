<x-layouts.app title="کارت‌های بانکی">
  <div class="list-head">
    <span class="list-head-ico acc-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="6" width="17" height="12" rx="2.5"/><path d="M3.5 10h17M7 14.5h4"/></svg></span>
    <div><h1>کارت‌های بانکی</h1><p>این کارت‌ها در صفحه «شارژ کیف پول» همکاران نشان داده می‌شوند (حداکثر ۵ کارت).</p></div>
  </div>
  @if($cards)
    <div class="bank-cards mb-16">@foreach($cards as $c)<x-bank-card :card="$c"/>@endforeach</div>
  @endif
  <form method="post" action="{{ route('accounting.cards.save') }}" class="card form-card">
    @csrf @method('PUT')
    @for($i = 0; $i < max(2, count($cards) + 1) && $i < 5; $i++)
      @php($c = old('cards.'.$i, $cards[$i] ?? []))
      <fieldset class="card-edit">
        <legend>کارت {{ fa($i + 1) }}</legend>
        <div class="form-grid">
          <div class="field"><label>شماره کارت (۱۶ رقم)</label><input class="input ltr" name="cards[{{ $i }}][number]" value="{{ $c['number'] ?? '' }}" inputmode="numeric" maxlength="30" placeholder="6037 9911 2345 6789"></div>
          <div class="field"><label>شماره شبا (بدون IR)</label><input class="input ltr" name="cards[{{ $i }}][sheba]" value="{{ $c['sheba'] ?? '' }}" inputmode="numeric" maxlength="40" placeholder="IR 12 0170 0000 0012 3456 7890 01"></div>
          <div class="field"><label>نام صاحب حساب</label><input class="input" name="cards[{{ $i }}][holder]" value="{{ $c['holder'] ?? '' }}" maxlength="80"></div>
          <div class="field"><label>بانک</label><input class="input" name="cards[{{ $i }}][bank]" value="{{ $c['bank'] ?? '' }}" maxlength="40" placeholder="مثلاً ملی"></div>
        </div>
      </fieldset>
    @endfor
    <p class="muted small">برای حذف یک کارت، شماره کارت و شبای آن را خالی کنید.</p>
    <button class="btn" type="submit">ذخیره</button>
  </form>
</x-layouts.app>
