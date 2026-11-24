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
    <button type="submit" class="block btn">ورود</button>
  </form>
</x-layouts.guest>
