<x-layouts.app :title="$r->exists ? 'ویرایش روتر' : 'افزودن روتر'">
  <div class="page-head">
    <div><h1>{{ $r->exists ? 'ویرایش روتر '.$r->name : 'افزودن روتر میکروتیک' }}</h1><p class="muted">رمز رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود.</p></div>
    <a class="btn btn-secondary" href="{{ $r->exists ? route('routers.show', $r) : route('routers.index') }}">بازگشت</a>
  </div>
  <form method="post" action="{{ $r->exists ? route('routers.update', $r) : route('routers.store') }}" class="w-640">
    @csrf @if($r->exists) @method('PUT') @endif
    <section class="card step">
      <header class="step-head"><span class="step-no">۱</span><div><h2>آدرس و روش اتصال</h2><p class="muted small">هاست پنل باید به این آدرس و پورت دسترسی داشته باشد.</p></div></header>
      <div class="form-grid">
        <div class="field"><label for="name">نام</label><input class="input" id="name" name="name" required maxlength="80" value="{{ old('name', $r->name) }}" placeholder="مثلاً سرور تهران ۱"></div>
        <div class="field"><label for="host">IP یا دامنه روتر</label><input class="input ltr" id="host" name="host" required value="{{ old('host', $r->host) }}" placeholder="185.x.x.x"></div>
      </div>
      <div class="field">
        <label>روش اتصال</label>
        <div class="picks picks-sm">
          @foreach(\App\Models\Router::TRANSPORTS as $k => [$label, $port])
            <label class="pick">
              <input class="pick-input" type="radio" name="transport" value="{{ $k }}" data-port="{{ $port }}" @checked(old('transport', $r->transport) === $k)>
              <span class="pick-body"><span class="pick-check">✓</span><span class="pick-title">{{ $k === 'rest' ? 'REST (HTTPS)' : ($k === 'api-ssl' ? 'API-SSL' : 'API') }}</span>
                <span class="pick-specs"><span>{{ $label }}</span><span>پورت پیش‌فرض {{ fa($port) }}</span></span></span>
            </label>
          @endforeach
        </div>
        <div class="help">REST نیازمند RouterOS 7.1+ و سرویس www-ssl با گواهی است. API ساده رمز را رمزنگاری‌نشده می‌فرستد؛ فقط وقتی هاست و روتر در یک شبکه خصوصی‌اند.</div>
      </div>
      <div class="form-grid">
        <div class="field"><label for="port">پورت</label><input class="input ltr" type="number" id="port" name="port" min="1" max="65535" required value="{{ old('port', $r->port) }}"></div>
      </div>
    </section>
    <section class="card step">
      <header class="step-head"><span class="step-no">۲</span><div><h2>کاربر روتر</h2><p class="muted small">یک کاربر مخصوص پنل بسازید (اسکریپت آماده در صفحه روتر)؛ از کاربر admin استفاده نکنید.</p></div></header>
      <div class="form-grid">
        <div class="field"><label for="username">نام کاربری</label><div class="input-group"><span class="addon addon-icon"><x-icon name="user"/></span><input class="input ltr" id="username" name="username" required value="{{ old('username', $r->username ?: 'panel') }}" autocomplete="off"></div></div>
        <div class="field"><label for="password">رمز</label><div class="input-group"><span class="addon addon-icon"><x-icon name="key"/></span><input class="input ltr" type="password" id="password" name="password" @unless($r->exists) required @endunless autocomplete="new-password" placeholder="{{ $r->exists ? 'خالی = بدون تغییر' : '' }}"><button type="button" class="addon addon-btn" data-gen-pass="#password">🎲 تصادفی</button></div>
          <div class="help">رمز تصادفی را کپی کنید؛ در اسکریپت ساخت کاربر روی روتر لازم است.</div></div>
      </div>
      <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $r->exists ? $r->is_active : true))> فعال</label>
    </section>
    <div class="submit-bar"><div class="muted small"><x-icon name="key"/> با تغییر آدرس یا روش اتصال، اثر انگشت گواهی باید دوباره تأیید شود.</div><button class="btn btn-lg" type="submit">ذخیره</button></div>
  </form>
</x-layouts.app>
