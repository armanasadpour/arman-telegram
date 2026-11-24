<x-layouts.app title="اتصال به سرور IBSng">
  <div class="page-head">
    <div>
      <h1>اتصال به سرور IBSng</h1>
      <p class="muted">آخرین تماس موفق: {{ $health['ok_at'] ? jdate(\Carbon\Carbon::parse($health['ok_at'])).' ('.fa_ago(\Carbon\Carbon::parse($health['ok_at'])).')' : 'هنوز نه' }}
        @if($health['latency_ms'] !== null) — زمان پاسخ: {{ fa($health['latency_ms']) }} میلی‌ثانیه @endif</p>
    </div>
    @can('settings.update')
      <form method="post" action="{{ route('ibs.settings.test') }}">@csrf<button class="btn btn-secondary" type="submit">تست اتصال</button></form>
    @endcan
  </div>

  @if($test)
    <div @class(['alert', 'alert-success' => $test['ok'], 'alert-danger' => ! $test['ok']])>
      @if($test['ok'])
        اتصال برقرار است — مدیر: <bdi>{{ $test['admin'] }}</bdi> — {{ fa($test['groups']) }} گروه — {{ fa($test['ms']) }} میلی‌ثانیه
      @else
        اتصال ناموفق: {{ $test['error'] }}
      @endif
    </div>
  @endif
  @if($health['breaker_open'])
    <div class="alert alert-warning">به‌دلیل چند خطای پیاپی، ارتباط با سرور برای یک دقیقه متوقف شده است تا پنل کند نشود. آخرین خطا: {{ $health['error'] }}</div>
  @elseif($health['error_at'] && (! $health['ok_at'] || $health['error_at'] > $health['ok_at']))
    <div class="alert alert-warning">آخرین خطا ({{ fa_ago(\Carbon\Carbon::parse($health['error_at'])) }}): {{ $health['error'] }}</div>
  @endif

  <div class="card w-640">
    <form method="post" action="{{ route('ibs.settings.update') }}">
      @csrf @method('put')
      <h2>سرور</h2>
      <div class="form-grid">
        <div class="field"><label for="host">میزبان (IP یا نام)</label><input id="host" name="host" class="input ltr" value="{{ old('host', $host) }}" required></div>
        <div class="field"><label for="port">پورت XML-RPC</label><input id="port" name="port" type="number" class="input ltr" value="{{ old('port', $port) }}" required></div>
        <div class="field"><label for="username">نام کاربری مدیر سرور</label><input id="username" name="username" class="input ltr" value="{{ old('username', $username) }}" required autocomplete="off"></div>
        <div class="field"><label for="password">رمز مدیر سرور</label><input id="password" name="password" type="password" class="input ltr" autocomplete="new-password" placeholder="{{ $hasPassword ? '•••••••• (ذخیره‌شده؛ برای تغییر وارد کنید)' : '' }}" @unless($hasPassword) required @endunless>
          <div class="help">رمزنگاری‌شده ذخیره می‌شود و هرگز نمایش داده نمی‌شود.</div></div>
        <div class="field"><label for="timeout">مهلت پاسخ (ثانیه)</label><input id="timeout" name="timeout" type="number" class="input ltr" value="{{ old('timeout', $timeout) }}" required></div>
      </div>
      <h2>اکانت‌ها</h2>
      <div class="form-grid">
        <div class="field">
          <label for="renewal_mode">سیاست تمدید</label>
          <select id="renewal_mode" name="renewal_mode" class="input">
            <option value="smart" @selected(old('renewal_mode', $renewalMode) === 'smart')>هوشمند: فعال ← از انقضای فعلی؛ منقضی ← از الان</option>
            <option value="from_expiry" @selected(old('renewal_mode', $renewalMode) === 'from_expiry')>همیشه از تاریخ انقضای فعلی</option>
          </select>
        </div>
        <div class="field"><label for="prefix">پیشوند نام کاربری خودکار</label><input id="prefix" name="prefix" class="input ltr" value="{{ old('prefix', $prefix) }}" required><div class="help">مثلاً u ← u482913</div></div>
        <div class="field"><label for="stale_minutes">هشدار داده قدیمی پس از (دقیقه)</label><input id="stale_minutes" name="stale_minutes" type="number" class="input ltr" value="{{ old('stale_minutes', $staleMinutes) }}" required></div>
      </div>
      @can('settings.sensitive')
        <button class="btn" type="submit">ذخیره تنظیمات</button>
        <p class="help muted">ذخیره این بخش عملیات حساس است و تأیید مجدد رمز پنل را می‌خواهد.</p>
      @else
        <p class="muted">برای تغییر این تنظیمات مجوز «عملیات حساس تنظیمات» لازم است.</p>
      @endcan
    </form>
  </div>
  <div class="card w-640">
    <h2>امنیت ارتباط</h2>
    <p class="muted m0">پروتکل ارتباط با سرور رمزنگاری ندارد. پورت سرور باید در فایروال فقط برای IP همین هاست باز باشد. برای سرویس دائمی، شبکه داخلی بین هاست و سرور یا یک واسط HTTPS روی سرور توصیه می‌شود.</p>
  </div>
</x-layouts.app>
