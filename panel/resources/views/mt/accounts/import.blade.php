<x-layouts.app :title="'وارد کردن از روتر — '.$g->name">
  <div class="page-head">
    <div>
      <h1>وارد کردن {{ $g->isWireguard() ? 'Peerهای WireGuard' : 'کاربران L2TP' }} از روتر</h1>
      <p class="muted">گروه «{{ $g->name }}» — روتر {{ $g->router->name }}{{ $g->isWireguard() ? ' — اینترفیس '.$g->wg_interface : '' }}. روی روتر چیزی تغییر نمی‌کند؛ مصرف از همین لحظه شمرده می‌شود.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('mt.groups.index') }}">بازگشت</a>
  </div>

  @if(empty($rows))
    <div class="card"><div class="empty"><x-icon name="inbox"/><div>موردی برای وارد کردن نیست؛ همه {{ $g->isWireguard() ? 'Peerهای این اینترفیس' : 'کاربران PPP' }} از قبل در پنل هستند.</div></div></div>
  @else
  <form method="post" action="{{ route('mt.import.store', $g) }}" class="create-form" data-confirm="موارد انتخاب‌شده وارد پنل شوند؟">
    @csrf
    <section class="card step">
      <header class="step-head"><span class="step-no">۱</span><div><h2>انتخاب ({{ fa(count($rows)) }} مورد)</h2>
        @if($g->isWireguard())<p class="muted small">نام کاربری از Comment یا IP ساخته می‌شود. روتر کلید خصوصی مشتری را ندارد؛ مشتری با همان فایل قبلی‌اش وصل می‌ماند.</p>@endif</div></header>
      <label class="check"><input type="checkbox" checked data-check-all="input[name='ids[]']"> انتخاب همه</label>
      <div class="table-wrap"><table class="table">
        <thead><tr><th></th><th>نام در پنل</th><th>مشخصات</th><th>وضعیت روی روتر</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
          <tr>
            <td><input type="checkbox" class="cb" name="ids[]" value="{{ $r['key'] }}" checked aria-label="انتخاب"></td>
            <td><bdi class="ltr">{{ $r['name'] }}</bdi></td>
            <td class="small ltr">{{ $r['detail'] }}</td>
            <td>@if($r['disabled'])<span class="badge">غیرفعال</span>@else<span class="badge badge-success">فعال</span>@endif</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </section>
    <section class="card step">
      <header class="step-head"><span class="step-no">۲</span><div><h2>محدودیت برای همه (اختیاری)</h2><p class="muted small">خالی = نامحدود. بعداً برای هر اکانت جدا هم قابل تغییر است. مدت از اولین اتصال بعد از ورود شمرده می‌شود.</p></div></header>
      <div class="form-grid">
        <div class="field"><label for="days">مدت</label><div class="input-group"><input id="days" name="days" type="number" min="1" max="3650" class="input ltr" placeholder="نامحدود" value="{{ $g->default_days }}"><span class="addon">روز</span></div></div>
        <div class="field"><label for="volume_gb">حجم</label><div class="input-group"><input id="volume_gb" name="volume_gb" type="number" step="0.1" min="0.1" class="input ltr" placeholder="نامحدود" value="{{ $g->default_volume_gb !== null ? (float) $g->default_volume_gb : '' }}"><span class="addon">گیگابایت</span></div></div>
      </div>
    </section>
    <div class="submit-bar"><div class="muted small">پس از ورود، انقضا و حجم مثل بقیه اکانت‌ها خودکار کنترل می‌شود.</div><button class="btn btn-lg" type="submit">وارد کردن به پنل</button></div>
  </form>
  @endif
</x-layouts.app>
