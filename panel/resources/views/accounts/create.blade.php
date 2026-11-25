<x-layouts.app title="ساخت اکانت">
  <div class="page-head">
    <h1>ساخت اکانت OpenVPN در سرور</h1>
    <a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a>
  </div>
  @if($packages->isNotEmpty())
    <div class="card w-640">
      <h2>ساخت از روی بسته</h2>
      <form method="post" action="{{ route('accounts.store-package') }}">
        @csrf
        <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
        <div class="field">
          <label for="package_id">بسته</label>
          <select id="package_id" name="package_id" class="input" required>
            @foreach($packages as $row)
              <option value="{{ $row['p']->id }}" @selected((int) old('package_id') === $row['p']->id)>{{ $row['p']->name }} — {{ $row['p']->summary() }} — {{ fa_money($row['q']->finalPrice) }}</option>
            @endforeach
          </select>
          <div class="help">گروه سرور، مدت، حجم و تعداد اتصال از بسته گرفته می‌شود و قیمت لحظه ساخت ثبت می‌شود.</div>
        </div>
        <div class="form-grid">
          <div class="field"><label for="pkg_username">نام کاربری (اختیاری)</label><input id="pkg_username" name="username" class="input ltr" maxlength="32" autocapitalize="none" spellcheck="false"></div>
          <div class="field"><label for="pkg_password">رمز (اختیاری)</label><input id="pkg_password" name="password" class="input ltr" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false"><div class="help">هر رمزی بخواهید؛ خالی = تصادفی.</div></div>
          <div class="field"><label for="pkg_comment">توضیح (اختیاری)</label><input id="pkg_comment" name="comment" class="input" maxlength="100"></div>
        </div>
        <button class="btn" type="submit">ساخت اکانت از بسته</button>
      </form>
    </div>
    <h2>یا ساخت دستی</h2>
  @endif
  @if($groups->isEmpty())
    <div class="alert alert-warning">هیچ گروهی از سرور همگام نشده است. @can('ibs_groups.view')<a href="{{ route('ibs.groups') }}">گروه‌ها را همگام‌سازی کنید.</a>@endcan</div>
  @else
  <div class="card w-640">
    <form method="post" action="{{ route('accounts.store') }}">
      @csrf
      <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
      <div class="field">
        <label for="group">گروه سرور</label>
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
          <input id="password" name="password" class="input ltr" value="{{ old('password') }}" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false">
          <div class="help">هر رمزی بخواهید؛ خالی بگذارید تا رمز تصادفی ساخته شود. رمز فقط یک بار نمایش داده می‌شود.</div>
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
      <button class="btn" type="submit">ساخت در سرور</button>
    </form>
  </div>
  @endif
</x-layouts.app>
