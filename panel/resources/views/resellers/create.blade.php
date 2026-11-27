<x-layouts.app title="همکار جدید">
  <div class="page-head"><h1>همکار جدید</h1><a class="btn btn-secondary" href="{{ route('resellers.index') }}">بازگشت</a></div>
  <div class="card w-640">
    <form method="post" action="{{ route('resellers.store') }}">
      @csrf
      <div class="form-grid">
        <div class="field"><label for="name">نام همکار / فروشگاه</label><input id="name" name="name" class="input" value="{{ old('name') }}" required maxlength="120"></div>
        <div class="field"><label for="phone">تلفن</label><input id="phone" name="phone" class="input ltr" value="{{ old('phone') }}" maxlength="20" inputmode="tel"></div>
        <div class="field"><label for="telegram">آیدی تلگرام</label><input id="telegram" name="telegram" class="input ltr" value="{{ old('telegram') }}" maxlength="64"></div>
        <div class="field"><label for="global_discount_percent">تخفیف سراسری (٪)</label><input id="global_discount_percent" name="global_discount_percent" type="number" step="0.01" min="0" max="100" class="input ltr" value="{{ old('global_discount_percent', 0) }}"><div class="help">روی قیمت پایه همه بسته‌ها؛ تخفیف دسته‌ای و قیمت اختصاصی بعداً در صفحه همکار.</div></div>
        <div class="field"><label for="account_prefix">پیشوند دائمی اکانت‌ها</label><input id="account_prefix" name="account_prefix" class="input ltr" value="{{ old('account_prefix') }}" required maxlength="8" autocapitalize="none" spellcheck="false" placeholder="مثلاً sam">
          <div class="help">همه اکانت‌هایی که این همکار می‌سازد با این پیشوند شروع می‌شوند (مثلاً sam + ali = samali). ۲ تا ۸ کاراکتر، حروف کوچک انگلیسی و عدد، شروع با حرف. <strong>بعداً قابل تغییر نیست.</strong></div>
          @error('account_prefix')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="username">نام کاربری ورود</label><input id="username" name="username" class="input ltr" value="{{ old('username') }}" required maxlength="60" autocapitalize="none"></div>
        <div></div>
        <div class="field"><label for="password">رمز ورود</label><input id="password" name="password" type="password" class="input ltr" autocomplete="new-password" required><div class="help">حداقل ۴ کاراکتر.</div></div>
        <div class="field"><label for="password_confirmation">تکرار رمز</label><input id="password_confirmation" name="password_confirmation" type="password" class="input ltr" autocomplete="new-password" required></div>
      </div>
      <button class="btn" type="submit">ایجاد همکار</button>
    </form>
  </div>
</x-layouts.app>
