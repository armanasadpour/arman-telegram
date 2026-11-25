<x-layouts.app :title="$target ? 'تمدید / افزایش حجم' : 'فروش اکانت جدید'">
  <div class="page-head">
    <div><h1>{{ $target ? 'تمدید یا افزایش حجم «'.$target->username.'»' : 'فروش اکانت جدید' }}</h1><p class="muted">موجودی شما: {{ fa_money($wallet->balance) }}</p></div>
    <a class="btn btn-secondary" href="{{ $target ? route('portal.accounts.show', $target) : route('portal.dashboard') }}">بازگشت</a>
  </div>
  @unless($me->isActive())<div class="alert alert-warning">حساب همکاری شما در حالت تعلیق است.</div>@endunless
  @if($rows->isEmpty())
    <div class="alert alert-warning">بسته قابل فروشی برای این کار وجود ندارد.</div>
  @else
  <div class="card w-640">
    <form method="post" action="{{ route('portal.sell.store') }}" data-confirm="مبلغ خرید از کیف پول شما کسر شود و کار انجام شود؟">
      @csrf
      <input type="hidden" name="sale_key" value="{{ old('sale_key', $saleKey) }}">
      @if($target)<input type="hidden" name="account_id" value="{{ $target->id }}">@endif
      <div class="field"><label for="package_id">بسته</label>
        <select id="package_id" name="package_id" class="input" required>
          @foreach($rows as $row)<option value="{{ $row['p']->id }}" @selected((int) old('package_id') === $row['p']->id)>{{ $row['p']->name }} — {{ $row['p']->summary() }} — خرید شما: {{ fa_money($row['q']->finalPrice) }}</option>@endforeach
        </select></div>
      <div class="form-grid">
        <div class="field"><label for="sale_price_toman">قیمت فروش به مشتری (تومان)</label><input id="sale_price_toman" name="sale_price_toman" type="number" min="0" class="input ltr" value="{{ old('sale_price_toman') }}" required><div class="help">سود شما = این مبلغ − قیمت خرید.</div></div>
        @unless($target)
          <div class="field"><label for="username">نام کاربری (اختیاری)</label><input id="username" name="username" class="input ltr" value="{{ old('username') }}" maxlength="32" autocapitalize="none"></div>
          <div class="field"><label for="password">رمز اکانت (اختیاری)</label><input id="password" name="password" class="input ltr" value="" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="help">هر رمزی بخواهید؛ خالی بگذارید تا رمز تصادفی ساخته شود.</div></div>
        @endunless
        <div class="field"><label for="customer_name">نام مشتری (اختیاری)</label><input id="customer_name" name="customer_name" class="input" value="{{ old('customer_name') }}" maxlength="120"></div>
        <div class="field"><label for="customer_phone">تلفن مشتری (اختیاری)</label><input id="customer_phone" name="customer_phone" class="input ltr" value="{{ old('customer_phone') }}" maxlength="20" inputmode="tel"></div>
      </div>
      <button class="btn" type="submit" @disabled(! $me->isActive())>کسر از کیف پول و انجام</button>
      <p class="help muted">اگر کار در سرور انجام نشود، مبلغ خودکار به کیف پول برمی‌گردد.</p>
    </form>
  </div>
  @endif
</x-layouts.app>
