<x-layouts.app title="درگاه‌های پرداخت">
  <div class="page-head">
    <div><h1>درگاه‌های پرداخت</h1>
      <p class="muted">فعال و آماده: {{ $enabled ? implode('، ', $enabled) : 'هیچ‌کدام' }}</p></div>
  </div>
  <div class="alert alert-warning small">آدرس بازگشت هر پرداخت خودکار ساخته می‌شود (<bdi class="ltr">{{ $callbackExample }}</bdi>). دامنه پنل را در پنل پذیرندگی هر درگاه ثبت کنید. هر درگاه را قبل از استفاده واقعی با یک پرداخت کم‌مبلغ آزمایش کنید. مقادیر رمزی پس از ذخیره نمایش داده نمی‌شوند؛ خالی گذاشتن یعنی «بدون تغییر».</div>
  <form method="post" action="{{ route('payments.settings.update') }}">
    @csrf @method('PUT')
    <div class="card"><h2>زرین‌پال</h2>@include('payments.partials.flag', ['k' => 'zarinpal.enabled', 'label' => 'فعال']) @include('payments.partials.flag', ['k' => 'zarinpal.sandbox', 'label' => 'حالت آزمایشی (sandbox)'])<div class="form-grid">@include('payments.partials.field', ['k' => 'zarinpal.merchant_id'])</div></div>
    <div class="card"><h2>بانک سامان (سپ)</h2>@include('payments.partials.flag', ['k' => 'sep.enabled', 'label' => 'فعال'])<div class="form-grid">@include('payments.partials.field', ['k' => 'sep.terminal_id'])</div></div>
    <div class="card"><h2>به‌پرداخت ملت</h2>@include('payments.partials.flag', ['k' => 'mellat.enabled', 'label' => 'فعال'])<div class="form-grid">@include('payments.partials.field', ['k' => 'mellat.terminal_id'])@include('payments.partials.field', ['k' => 'mellat.username'])@include('payments.partials.field', ['k' => 'mellat.password'])</div></div>
    <div class="card"><h2>سداد (بانک ملی)</h2>@include('payments.partials.flag', ['k' => 'sadad.enabled', 'label' => 'فعال'])<div class="form-grid">@include('payments.partials.field', ['k' => 'sadad.merchant_id'])@include('payments.partials.field', ['k' => 'sadad.terminal_id'])@include('payments.partials.field', ['k' => 'sadad.key'])</div></div>

    <div class="card">
      <h2>کارت به کارت</h2>@include('payments.partials.flag', ['k' => 'card.enabled', 'label' => 'فعال'])
      <p class="muted small">پرداخت‌کننده شماره پیگیری، ۴ رقم آخر کارت و (اختیاری) تصویر رسید را ثبت می‌کند؛ شارژ فقط پس از تأیید شما.</p>
      @for($i = 0; $i < 3; $i++)
        <div class="form-grid">
          <div class="field"><label for="card{{ $i }}">شماره کارت {{ fa($i + 1) }}</label><input class="input ltr" id="card{{ $i }}" name="cards[{{ $i }}][number]" inputmode="numeric" value="{{ old("cards.$i.number", $cards[$i]['number'] ?? '') }}"></div>
          <div class="field"><label for="holder{{ $i }}">نام صاحب کارت</label><input class="input" id="holder{{ $i }}" name="cards[{{ $i }}][holder]" value="{{ old("cards.$i.holder", $cards[$i]['holder'] ?? '') }}"></div>
          <div class="field"><label for="bank{{ $i }}">بانک</label><input class="input" id="bank{{ $i }}" name="cards[{{ $i }}][bank]" value="{{ old("cards.$i.bank", $cards[$i]['bank'] ?? '') }}"></div>
        </div>
      @endfor
    </div>

    <div class="card">
      <h2>تتر (USDT)</h2>@include('payments.partials.flag', ['k' => 'usdt.enabled', 'label' => 'فعال'])
      <p>@include('payments.partials.flag', ['k' => 'usdt.trc20.enabled', 'label' => 'TRC20']) @include('payments.partials.flag', ['k' => 'usdt.erc20.enabled', 'label' => 'ERC20']) @include('payments.partials.flag', ['k' => 'usdt.bep20.enabled', 'label' => 'BEP20'])</p>
      <div class="form-grid">@include('payments.partials.field', ['k' => 'usdt.rate_toman', 'type' => 'number'])@include('payments.partials.field', ['k' => 'usdt.address.trc20'])@include('payments.partials.field', ['k' => 'usdt.address.erc20'])@include('payments.partials.field', ['k' => 'usdt.address.bep20'])@include('payments.partials.field', ['k' => 'usdt.trongrid_key'])@include('payments.partials.field', ['k' => 'usdt.etherscan_key'])</div>
      <p class="muted small">تأیید خودکار از TronGrid و Etherscan v2 (یک کلید برای ERC20 و BEP20). اگر هاست به این سرویس‌ها دسترسی نداشته باشد، پرداخت برای بررسی دستی به صف می‌رود. نرخ را به‌روز نگه دارید؛ مبلغ تتر هر درخواست در لحظه ایجاد ثابت می‌شود.</p>
    </div>
    <button class="btn" type="submit">ذخیره تنظیمات پرداخت</button>
  </form>
</x-layouts.app>
