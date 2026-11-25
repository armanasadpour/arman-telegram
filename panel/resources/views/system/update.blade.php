<x-layouts.app title="بارگذاری به‌روزرسانی">
  <div class="page-head">
    <div><h1>بارگذاری به‌روزرسانی</h1><p class="muted">نسخه نصب‌شده: <strong class="ltr">{{ $version }}</strong></p></div>
    <a class="btn btn-secondary" href="{{ route('system.status') }}">بازگشت</a>
  </div>
  @unless($zip)<div class="alert alert-danger">افزونه zip در PHP هاست فعال نیست؛ از cPanel ← Select PHP Version آن را روشن کنید.</div>@endunless
  <div class="card w-640">
    <form method="post" action="{{ route('system.update.store') }}" enctype="multipart/form-data">
      @csrf
      <div class="field">
        <label for="package">فایل ZIP به‌روزرسانی یا بسته کامل</label>
        <input id="package" name="package" type="file" accept=".zip,application/zip" class="input" required>
        <div class="help">بسته سبک (vpn-panel-update-…) یا بسته کامل (vpn-panel-…). سقف آپلود این هاست: {{ $maxUpload < PHP_INT_MAX ? fa_bytes($maxUpload) : 'نامحدود' }}.</div>
      </div>
      <button class="btn btn-lg" type="submit">بارگذاری و نصب</button>
    </form>
    <ul class="muted small mt-8">
      <li>بعد از نصب فایل‌ها، به‌روزرسانی دیتابیس خودکار اجرا می‌شود.</li>
      <li>فایل ‎.env، تنظیمات، فایل‌های آپلودشده و لاگ‌ها دست نمی‌خورند.</li>
      <li>بسته سبک فقط روی همان نسخه‌ای نصب می‌شود که برایش ساخته شده؛ در غیر این صورت پیام می‌دهد و چیزی تغییر نمی‌کند.</li>
      <li>اگر بسته کامل از سقف آپلود بزرگ‌تر است، همان روش File Manager را برای آن استفاده کنید.</li>
    </ul>
  </div>
</x-layouts.app>
