<x-layouts.guest title="تأیید رمز عبور">
  <h1>تأیید هویت</h1>
  <p class="muted">این عملیات حساس است. برای ادامه، رمز عبور خود را دوباره وارد کنید.</p>
  <form method="post" action="{{ route('password.confirm') }}">
    @csrf
    <div class="field">
      <label for="password">رمز عبور</label>
      <input id="password" name="password" type="password" class="input ltr @error('password') is-invalid @enderror" autocomplete="current-password" required autofocus>
    </div>
    <div class="actions">
      <button type="submit" class="btn">تأیید و ادامه</button>
      <a class="btn btn-secondary" href="{{ url()->previous() === url()->current() ? route('dashboard') : url()->previous() }}">انصراف</a>
    </div>
  </form>
</x-layouts.guest>
