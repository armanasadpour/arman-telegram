<x-layouts.app :title="$target ? 'تمدید / افزایش حجم' : 'فروش اکانت جدید'">
  <div class="page-head">
    <div><h1>{{ $target ? 'تمدید یا افزایش حجم «'.$target->username.'»' : 'فروش اکانت جدید' }}</h1><p class="muted">موجودی شما: {{ fa_money($wallet->balance) }}</p></div>
    <a class="btn btn-secondary" href="{{ $target ? ($target instanceof \App\Models\MtAccount ? route('portal.mt.show', $target) : route('portal.accounts.show', $target)) : route('portal.dashboard') }}">بازگشت</a>
  </div>
  @unless($me->isActive())<div class="alert alert-warning">حساب همکاری شما در حالت تعلیق است.</div>@endunless
  @if($rows->isEmpty())
    <div class="alert alert-warning">بسته قابل فروشی برای این کار وجود ندارد.</div>
  @else
  <div class="card w-640">
    <form method="post" action="{{ route('portal.sell.store') }}" data-confirm="مبلغ خرید از کیف پول شما کسر شود و کار انجام شود؟">
      @csrf
      <input type="hidden" name="sale_key" value="{{ old('sale_key', $saleKey) }}">
      @if($target)<input type="hidden" name="{{ $target instanceof \App\Models\MtAccount ? 'mt_account_id' : 'account_id' }}" value="{{ $target->id }}">@endif
      <div class="field">
        <label>بسته را انتخاب کنید</label>
        @php($sel = (int) old('package_id', $rows->first()['p']->id))
        <div class="pkg-list">
          @foreach($rows as $row)
            @php($p = $row['p'])
            @php($proto = $p->isMikrotik() ? ($p->mtGroup?->protocol ?? 'l2tp') : 'openvpn')
            <label class="pkg-opt">
              <input type="radio" name="package_id" value="{{ $p->id }}" required @checked($sel === $p->id)>
              <span class="pkg-box">
                <x-proto-logo :proto="$proto" class="pkg-logo"/>
                <span class="pkg-main">
                  <strong class="pkg-name">{{ $p->name }}</strong>
                  <span class="pkg-chips">
                    @if($p->duration_days)<span class="chip-s">{{ fa($p->duration_days) }} روز</span>@endif
                    @if($p->hasVolume())<span class="chip-s">{{ fa_bytes((int) ((float) $p->volume_gb * 1073741824)) }}</span>@elseif($p->type !== 'topup')<span class="chip-s">حجم نامحدود</span>@endif
                    @if($p->multi_login)<span class="chip-s">{{ fa($p->multi_login) }} {{ $proto === 'wireguard' ? 'دستگاه' : 'اتصال' }}</span>@endif
                  </span>
                </span>
                <span class="pkg-price"><small>خرید شما</small><b>{{ fa_money($row['q']->finalPrice) }}</b></span>
                <span class="pkg-check" aria-hidden="true">✓</span>
              </span>
            </label>
          @endforeach
        </div>
      </div>
      <div class="form-grid">
        <div class="field"><label for="customer_name">👤 نام مشتری{{ $target ? ' (اختیاری)' : '' }}</label><input id="customer_name" name="customer_name" class="input" value="{{ old('customer_name') }}" maxlength="100" @unless($target) required @endunless>@error('customer_name')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="sale_price_toman">قیمت فروش به مشتری (تومان)</label><input id="sale_price_toman" name="sale_price_toman" type="number" min="0" class="input ltr" value="{{ old('sale_price_toman') }}" required><div class="help">سود شما = این مبلغ − قیمت خرید.</div></div>
        @unless($target)
          <div class="field"><label for="username">نام کاربری (اختیاری)</label><input id="username" name="username" class="input ltr" value="{{ old('username') }}" maxlength="32" autocapitalize="none"></div>
          <div class="field"><label for="password">رمز اکانت (اختیاری)</label><input id="password" name="password" class="input ltr" value="" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="help">هر رمزی بخواهید؛ خالی بگذارید تا رمز تصادفی ساخته شود. (WireGuard رمز ندارد؛ فایل و QR می‌گیرد.)</div></div>
        @endunless
        <div class="field"><label for="customer_phone">تلفن مشتری (اختیاری)</label><input id="customer_phone" name="customer_phone" class="input ltr" value="{{ old('customer_phone') }}" maxlength="20" inputmode="tel"></div>
      </div>
      <button class="btn" type="submit" @disabled(! $me->isActive())>کسر از کیف پول و انجام</button>
      <p class="help muted">اگر کار در سرور انجام نشود، مبلغ خودکار به کیف پول برمی‌گردد.</p>
    </form>
  </div>
  @endif
</x-layouts.app>
