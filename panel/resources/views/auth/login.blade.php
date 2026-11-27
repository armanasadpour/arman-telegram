<x-layouts.guest title="ورود">
  <h1>پنل فروش</h1>
  <p class="subtitle">ورود به حساب کاربری</p>
  <form method="post" action="{{ route('login') }}" novalidate>
    @csrf
    <div class="field">
      <label for="username">نام کاربری</label>
      <input id="username" name="username" class="input ltr @error('username') is-invalid @enderror" value="{{ old('username') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
    </div>
    <div class="field">
      <label for="password">رمز عبور</label>
      <input id="password" name="password" type="password" class="input ltr" autocomplete="current-password" required>
    </div>
    @if($captcha)
      <div class="field">
        <label for="captcha">کد امنیتی</label>
        <div class="captcha-row">
          <img src="{{ $captchaImg }}" alt="کد امنیتی" width="210" height="70" id="captcha-img" class="captcha-img">
          <button type="button" class="captcha-refresh" title="تصویر جدید" aria-label="تصویر جدید" data-captcha-url="{{ route('captcha') }}">↻</button>
        </div>
        <input id="captcha" name="captcha" class="input ltr @error('captcha') is-invalid @enderror" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="عدد داخل تصویر" required>
        @error('captcha')<div class="error">{{ $message }}</div>@enderror
      </div>
    @endif
    <button type="submit" class="block btn">ورود</button>
  </form>
</x-layouts.guest>
