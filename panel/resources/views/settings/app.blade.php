<x-layouts.app title="برنامه نصبی">
  <div class="page-head">
    <div><h1>برنامه نصبی (PWA)</h1><p class="muted">پنل را مثل یک برنامه روی گوشی و کامپیوتر نصب کنید؛ با آیکن خودش، تمام‌صفحه و بدون نوار مرورگر.</p></div>
  </div>

  <div class="grid two">
    <div class="card">
      <h2>نصب روی گوشی</h2>
      <button type="button" class="btn mb-12" data-install hidden>📲 نصب برنامه روی همین دستگاه</button>
      <ul class="muted small">
        <li><b>اندروید (Chrome):</b> دکمه «نصب برنامه» بالای صفحه، یا منوی ⋮ ← «Install app / افزودن به صفحه اصلی».</li>
        <li><b>آیفون (Safari):</b> دکمه اشتراک‌گذاری ⬆️ ← «Add to Home Screen».</li>
        <li><b>کامپیوتر (Chrome/Edge):</b> آیکن نصب در نوار آدرس.</li>
        <li>نصب فقط روی آدرس HTTPS کار می‌کند.</li>
      </ul>
    </div>
    <div class="card">
      <h2>پیش‌نمایش</h2>
      <div class="pwa-preview">
        <img src="{{ url('/pwa/icon-192.png') }}?v={{ $v }}" alt="آیکن" width="96" height="96">
        <div><strong>{{ $s['pwa.short_name'] }}</strong><div class="muted small">{{ $s['pwa.name'] }}</div></div>
      </div>
    </div>
  </div>

  @can('settings.update')
  <form method="post" action="{{ route('settings.app.update') }}" enctype="multipart/form-data" class="card w-640">
    @csrf @method('put')
    <div class="form-grid">
      <div class="field"><label for="name">نام برنامه</label><input id="name" name="name" class="input" maxlength="45" required value="{{ old('name', $s['pwa.name']) }}"><div class="help">در صفحه شروع و فهرست برنامه‌ها.</div></div>
      <div class="field"><label for="short_name">نام کوتاه (زیر آیکن)</label><input id="short_name" name="short_name" class="input" maxlength="15" required value="{{ old('short_name', $s['pwa.short_name']) }}"></div>
      <div class="field"><label for="theme">رنگ نوار بالا</label><input id="theme" name="theme" type="color" class="input input-color" value="{{ old('theme', $s['pwa.theme']) }}"></div>
      <div class="field"><label for="background">رنگ پس‌زمینه شروع و آیکن</label><input id="background" name="background" type="color" class="input input-color" value="{{ old('background', $s['pwa.background']) }}"></div>
      <div class="field"><label for="start">صفحه شروع</label>
        <select id="start" name="start" class="input">
          <option value="dashboard" @selected(old('start', $s['pwa.start']) === 'dashboard')>داشبورد</option>
          <option value="accounts" @selected(old('start', $s['pwa.start']) === 'accounts')>اکانت‌ها</option>
        </select></div>
      <div class="field"><label for="icon">آیکن (PNG یا JPG مربعی، ترجیحاً ۵۱۲×۵۱۲)</label><input id="icon" name="icon" type="file" accept="image/png,image/jpeg,image/webp" class="input">
        @unless($gd)<div class="error">افزونه GD در PHP هاست فعال نیست؛ آیکن قابل تغییر نیست.</div>@endunless</div>
    </div>
    @if($hasIcon)<label class="check"><input type="checkbox" name="remove_icon" value="1"> حذف آیکن و برگشت به آیکن پیش‌فرض</label>@endif
    <button class="btn mt-8" type="submit">ذخیره</button>
  </form>
  @endcan
</x-layouts.app>
