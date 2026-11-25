<x-layouts.app title="وضعیت سرویس‌ها">
  <div class="page-head">
    <div>
      <h1>وضعیت سرویس‌ها</h1>
      <p class="muted">بررسی زنده در {{ jdate($checkedAt, 'Y/m/d H:i:s') }}</p>
    </div>
    <div class="actions">
      @if(auth()->user()->isSuperAdmin())<a class="btn" href="{{ route('system.update') }}">بارگذاری به‌روزرسانی</a>@endif
      <a class="btn btn-secondary" href="{{ route('system.status') }}">بررسی دوباره</a>
    </div>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table class="table stack">
        <thead><tr><th>بخش</th><th>وضعیت</th><th>جزئیات</th></tr></thead>
        <tbody>
        @foreach($checks as $c)
          <tr>
            <td data-label="بخش">{{ $c['label'] }}</td>
            <td data-label="وضعیت">
              @if($c['status'] === 'ok')<span class="badge badge-success">سالم</span>
              @elseif($c['status'] === 'warn')<span class="badge badge-warning">هشدار</span>
              @else<span class="badge badge-danger">خطا</span>@endif
            </td>
            <td data-label="جزئیات">{{ $c['detail'] }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
  @if($pending > 0 && auth()->user()->isSuperAdmin())
    <div class="card card-danger">
      <h2>به‌روزرسانی دیتابیس به نسخه {{ config('panel.version') }}</h2>
      <p class="muted">{{ fa($pending) }} تغییر دیتابیس اجرا نشده است. پیش از اجرا از دیتابیس نسخه پشتیبان بگیرید (cPanel ← Backup).</p>
      <form method="post" action="{{ route('system.upgrade') }}" @if(session('auto_upgrade')) data-autosubmit @endif>
        @csrf
        <button class="btn" type="submit">اجرای به‌روزرسانی</button>
      </form>
    </div>
  @endif
  <div class="card">
    <h2>تنظیم Cron در cPanel</h2>
    <p class="muted">در cPanel ← Cron Jobs، یک کار «Once Per Minute» با دستور زیر بسازید (اگر هاست بازه یک‌دقیقه‌ای اجازه نمی‌دهد، کوتاه‌ترین بازه مجاز را انتخاب کنید):</p>
    <pre class="code">{{ PHP_BINDIR }}/php {{ base_path('artisan') }} schedule:run >/dev/null 2>&1</pre>
    @if(config('panel.cron_key'))
      <p class="muted">اگر هاست اجرای PHP از Cron را مجاز نمی‌داند، به‌جای آن این آدرس را هر دقیقه با wget/curl فراخوانی کنید (این آدرس را محرمانه نگه دارید):</p>
      <pre class="code">wget -q -O /dev/null "{{ route('cron', config('panel.cron_key')) }}"</pre>
    @endif
  </div>
</x-layouts.app>
