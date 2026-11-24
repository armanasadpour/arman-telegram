<x-layouts.app title="تغییر رمز عبور">
  <div class="page-head"><h1>تغییر رمز عبور</h1></div>
  <div class="w-520 card">
    <p class="muted">پس از تغییر رمز، از همه دستگاه‌های دیگر خارج می‌شوید.
      @if(auth()->user()->password_changed_at) آخرین تغییر: {{ jdate(auth()->user()->password_changed_at) }} @endif
    </p>
    <form method="post" action="{{ route('profile.password.update') }}">
      @csrf @method('put')
      <div class="field">
        <label for="current_password">رمز فعلی</label>
        <input id="current_password" name="current_password" type="password" class="input ltr @error('current_password') is-invalid @enderror" autocomplete="current-password" required>
      </div>
      <div class="field">
        <label for="password">رمز جدید</label>
        <input id="password" name="password" type="password" class="input ltr @error('password') is-invalid @enderror" autocomplete="new-password" required>
        <div class="help">حداقل ۱۰ کاراکتر، شامل حرف و عدد.</div>
      </div>
      <div class="field">
        <label for="password_confirmation">تکرار رمز جدید</label>
        <input id="password_confirmation" name="password_confirmation" type="password" class="input ltr" autocomplete="new-password" required>
      </div>
      <button type="submit" class="btn">ذخیره رمز جدید</button>
    </form>
  </div>
</x-layouts.app>
