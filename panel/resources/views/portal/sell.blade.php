<x-layouts.app :title="$target ? 'تمدید / افزایش حجم' : 'فروش اکانت جدید'">
  @php($ic = [
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/>',
    'coins' => '<ellipse cx="12" cy="7" rx="7" ry="3"/><path d="M5 7v5c0 1.7 3.1 3 7 3s7-1.3 7-3V7M5 12v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/>',
    'at' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/>',
    'lock' => '<rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
    'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z"/>',
  ])
  <div class="sell-head">
    <div><h1>{{ $target ? 'تمدید / افزایش حجم «'.$target->username.'»' : 'فروش اکانت جدید' }}</h1><p class="muted">موجودی شما: {{ fa_money($wallet->balance) }}</p></div>
    <a class="pill-btn" href="{{ $target ? ($target instanceof \App\Models\MtAccount ? route('portal.mt.show', $target) : route('portal.accounts.show', $target)) : route('portal.dashboard') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 14 4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3"/></svg>بازگشت</a>
  </div>
  @unless($me->isActive())<div class="alert alert-warning">حساب همکاری شما در حالت تعلیق است.</div>@endunless
  @if($rows->isEmpty())
    <div class="alert alert-warning">بسته قابل فروشی برای این کار وجود ندارد.</div>
  @else
  <div class="card form-card w-640">
    <form method="post" action="{{ route('portal.sell.store') }}" data-confirm="مبلغ خرید از کیف پول شما کسر شود و کار انجام شود؟">
      @csrf
      <input type="hidden" name="sale_key" value="{{ old('sale_key', $saleKey) }}">
      @if($target)<input type="hidden" name="{{ $target instanceof \App\Models\MtAccount ? 'mt_account_id' : 'account_id' }}" value="{{ $target->id }}">@endif

      @php($sel = (int) old('package_id', $rows->first()['p']->id))
      <div class="field">
        <label>بسته</label>
        <div class="dd" data-dd>
          <button type="button" class="dd-trigger" data-dd-trigger aria-haspopup="listbox" aria-expanded="false"><span class="dd-current"></span><svg class="dd-caret" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg></button>
          <div class="dd-panel" role="listbox" hidden>
            @foreach($rows->groupBy(fn ($r) => $r['p']->isMikrotik() ? ($r['p']->mtGroup?->protocol ?? 'l2tp') : 'openvpn') as $proto => $group)
              <div class="dd-group">{{ ['openvpn' => 'OpenVPN', 'l2tp' => 'L2TP/IPsec', 'wireguard' => 'WireGuard'][$proto] ?? $proto }}</div>
              @foreach($group as $row)
                @php($p = $row['p'])
                <label class="dd-opt">
                  <input type="radio" name="package_id" value="{{ $p->id }}" required @checked($sel === $p->id)>
                  <span class="dd-row">
                    <x-proto-logo :proto="$proto" class="dd-logo"/>
                    <span class="dd-text"><strong>{{ $p->name }}</strong><small>{{ $p->summary() }}</small></span>
                    <span class="dd-price">{{ fa_money($row['q']->finalPrice) }}</span>
                    <span class="dd-check" aria-hidden="true">✓</span>
                  </span>
                </label>
              @endforeach
            @endforeach
          </div>
        </div>
        <div class="help">قیمت کنار هر بسته، قیمت خرید شماست.</div>
      </div>

      <div class="field"><label for="customer_name">نام مشتری{{ $target ? ' (اختیاری)' : '' }}</label>
        <div class="input-ico"><input id="customer_name" name="customer_name" class="input" value="{{ old('customer_name') }}" maxlength="100" @unless($target) required @endunless><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic['user'] !!}</svg></div>
        @error('customer_name')<div class="error">{{ $message }}</div>@enderror</div>
      <div class="field"><label for="sale_price_toman">قیمت فروش به مشتری (تومان)</label>
        <div class="input-ico"><input id="sale_price_toman" name="sale_price_toman" data-money inputmode="numeric" class="input ltr" value="{{ old('sale_price_toman') }}" required><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic['coins'] !!}</svg></div>
        <div class="help">سود شما = این مبلغ − قیمت خرید.</div></div>
      @unless($target)
        <div class="field"><label for="username">نام کاربری اکانت</label>
          @php($pre = (string) $me->account_prefix)
          <div class="input-ico"><input id="username" name="username" class="input ltr un-locked" value="{{ $pre.preg_replace('/^'.preg_quote($pre, '/').'/', '', (string) old('username')) }}" maxlength="{{ $pre !== '' ? strlen($pre) + 24 : 32 }}" autocapitalize="none" spellcheck="false" autocomplete="off" required @if($pre !== '') data-lock-prefix="{{ $pre }}" @endif><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic['at'] !!}</svg></div>
          <div class="help">@if($pre !== '')<bdi class="ltr"><strong>{{ $pre }}</strong></bdi> پیشوند ثابت اکانت‌های شماست و پاک نمی‌شود؛ ادامه نام را بنویسید (مثلاً <bdi class="ltr">{{ $pre }}ali</bdi>). @endif حروف کوچک انگلیسی، عدد، «_»، «.» یا «-».</div>
          @error('username')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="password">رمز اکانت (اختیاری)</label>
          <div class="input-ico"><input id="password" name="password" class="input ltr" value="" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic['lock'] !!}</svg></div>
          <div class="help">هر رمزی بخواهید؛ خالی بگذارید تا رمز تصادفی ساخته شود. (WireGuard رمز ندارد: فایل و QR می‌گیرد.)</div></div>
      @endunless
      <div class="field"><label for="customer_phone">تلفن مشتری (اختیاری)</label>
        <div class="input-ico"><input id="customer_phone" name="customer_phone" class="input ltr" value="{{ old('customer_phone') }}" maxlength="20" inputmode="tel"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic['phone'] !!}</svg></div></div>
      <button class="btn btn-lg w-full sell-submit" type="submit" @disabled(! $me->isActive())><span>+</span> {{ $target ? 'تمدید / افزایش حجم' : 'ثبت اکانت جدید' }}</button>
      <p class="help muted">مبلغ خرید از کیف پول کسر می‌شود؛ اگر کار در سرور انجام نشود، خودکار برمی‌گردد.</p>
    </form>
  </div>
  @endif
</x-layouts.app>
