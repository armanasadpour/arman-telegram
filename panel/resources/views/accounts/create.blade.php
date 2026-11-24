<x-layouts.app title="ساخت اکانت">
  <div class="page-head">
    <h1>ساخت اکانت OpenVPN در IBSng</h1>
    <a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a>
  </div>
  @if($groups->isEmpty())
    <div class="alert alert-warning">هیچ گروهی از IBSng همگام نشده است. @can('ibs_groups.view')<a href="{{ route('ibs.groups') }}">گروه‌ها را همگام‌سازی کنید.</a>@endcan</div>
  @else
  <div class="card w-640">
    <form method="post" action="{{ route('accounts.store') }}">
      @csrf
      <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
      <div class="field">
        <label for="group">گروه IBSng</label>
        <select id="group" name="group" class="input" required>
          @foreach($groups as $g)
            <option value="{{ $g->name }}" @selected(old('group') === $g->name)>
              {{ $g->name }} — {{ fa_duration($g->rel_exp_seconds) }}، {{ $g->multi_login !== null ? fa($g->multi_login).' اتصال' : '' }}، {{ $g->charge?->isVolume() ? 'حجمی' : 'بدون محدودیت حجم' }}
            </option>
          @endforeach
        </select>
        <div class="help">مدت اعتبار، تعداد اتصال و تعرفه از گروه گرفته می‌شود؛ در صورت نیاز پایین‌تر برای همین اکانت تغییر دهید.</div>
      </div>
      <div class="form-grid">
        <div class="field">
          <label for="username">نام کاربری (اختیاری)</label>
          <input id="username" name="username" class="input ltr" value="{{ old('username') }}" maxlength="32" autocapitalize="none" spellcheck="false">
          <div class="help">خالی بگذارید تا خودکار ساخته شود. فقط حروف کوچک انگلیسی، عدد، نقطه، خط تیره، زیرخط.</div>
        </div>
        <div class="field">
          <label for="password">رمز (اختیاری)</label>
          <input id="password" name="password" class="input ltr" value="{{ old('password') }}" maxlength="32" autocomplete="off">
          <div class="help">خالی بگذارید تا رمز ۱۰ کاراکتری امن ساخته شود. رمز فقط یک بار نمایش داده می‌شود.</div>
        </div>
        <div class="field">
          <label for="days">مدت اعتبار (روز، اختیاری)</label>
          <input id="days" name="days" type="number" min="1" max="3650" class="input ltr" value="{{ old('days') }}">
          <div class="help">از اولین اتصال شمرده می‌شود. خالی = مدت گروه.</div>
        </div>
        <div class="field">
          <label for="multi_login">اتصال همزمان (اختیاری)</label>
          <input id="multi_login" name="multi_login" type="number" min="1" max="255" class="input ltr" value="{{ old('multi_login') }}">
        </div>
        <div class="field">
          <label for="volume_mb">حجم (مگابایت) — فقط گروه حجمی</label>
          <input id="volume_mb" name="volume_mb" type="number" min="1" class="input ltr" value="{{ old('volume_mb') }}">
          <div class="help">مثلاً ۱۰ گیگ = ۱۰۲۴۰. برای گروه‌های بدون محدودیت حجم خالی بماند.</div>
        </div>
        <div class="field">
          <label for="comment">توضیح (اختیاری)</label>
          <input id="comment" name="comment" class="input" value="{{ old('comment') }}" maxlength="150">
        </div>
      </div>
      <button class="btn" type="submit">ساخت در IBSng</button>
    </form>
  </div>
  @endif
</x-layouts.app>
