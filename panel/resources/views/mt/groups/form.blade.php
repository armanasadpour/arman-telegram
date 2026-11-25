@php($wg = $g->protocol === 'wireguard')
<x-layouts.app :title="$g->exists ? 'ویرایش گروه '.$g->name : 'گروه جدید '.($wg ? 'WireGuard' : 'L2TP')">
  <div class="page-head">
    <div>
      <h1>{{ $g->exists ? 'ویرایش گروه «'.$g->name.'»' : 'گروه جدید '.($wg ? 'WireGuard' : 'L2TP/IPsec') }}</h1>
      <p class="muted">روتر: <strong>{{ $g->router->name }}</strong> <bdi class="ltr">({{ $g->router->host }})</bdi></p>
    </div>
    <a class="btn btn-secondary" href="{{ route('mt.groups.index') }}">بازگشت</a>
  </div>

  @if($routerError)
    <div class="alert alert-warning">خواندن فهرست‌ها از روتر ممکن نشد ({{ $routerError }})؛ آخرین نتیجه «تست اتصال» نمایش داده می‌شود. هنگام ذخیره، روتر باید در دسترس باشد.</div>
  @endif

  <form method="post" action="{{ $g->exists ? route('mt.groups.update', $g) : route('mt.groups.store') }}" class="create-form">
    @csrf
    @if($g->exists) @method('put') @else
      <input type="hidden" name="router_id" value="{{ $g->router_id }}"><input type="hidden" name="protocol" value="{{ $g->protocol }}">
    @endif

    <section class="card step">
      <header class="step-head"><span class="step-no">۱</span><div><h2>مشخصات</h2></div></header>
      <div class="form-grid">
        <div class="field"><label for="name">نام گروه</label><input id="name" name="name" class="input" required maxlength="80" value="{{ old('name', $g->name) }}" placeholder="{{ $wg ? 'مثلاً WireGuard ایران' : 'مثلاً L2TP یک‌ماهه' }}">@error('name')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="endpoint_host">آدرس اتصال مشتری</label><input id="endpoint_host" name="endpoint_host" class="input ltr" maxlength="255" value="{{ old('endpoint_host', $g->endpoint_host) }}" placeholder="{{ $g->router->host }}"><div class="help">دامنه یا IP که مشتری در برنامه وارد می‌کند. خالی = آدرس روتر.</div>@error('endpoint_host')<div class="error">{{ $message }}</div>@enderror</div>
        <div class="field"><label for="dns">DNS</label><input id="dns" name="dns" class="input ltr" maxlength="200" value="{{ old('dns', $g->dns) }}" placeholder="1.1.1.1,8.8.8.8">@error('dns')<div class="error">{{ $message }}</div>@enderror</div>
      </div>
    </section>

    @if($wg)
      <section class="card step">
        <header class="step-head"><span class="step-no">۲</span><div><h2>اینترفیس WireGuard</h2><p class="muted small">کلید عمومی، پورت و شبکه هنگام ذخیره از روتر خوانده می‌شود.</p></div></header>
        <div class="form-grid">
          <div class="field"><label for="wg_interface">اینترفیس</label>
            <select id="wg_interface" name="wg_interface" class="input" required>
              @forelse($wgInterfaces as $w)
                <option value="{{ $w['name'] }}" @selected(old('wg_interface', $g->wg_interface) === $w['name'])>{{ $w['name'] }} — پورت {{ $w['port'] }} — {{ $w['address'] ?? 'بدون IP!' }}</option>
              @empty
                <option value="">اینترفیس WireGuard روی روتر نیست</option>
              @endforelse
            </select>@error('wg_interface')<div class="error">{{ $message }}</div>@enderror
            @if($g->exists && $g->wg_network)<div class="help ltr">{{ $g->wg_network }} · server {{ $g->wg_server_ip }} · :{{ $g->wg_listen_port }}</div>@endif
          </div>
          <div class="field"><label for="rate_limit">محدودیت سرعت هر اکانت (اختیاری)</label><input id="rate_limit" name="rate_limit" class="input ltr" value="{{ old('rate_limit', $g->rate_limit) }}" placeholder="10M/20M"><div class="help">آپلود/دانلود؛ با Simple Queue روی IP هر مشتری اعمال می‌شود.</div>@error('rate_limit')<div class="error">{{ $message }}</div>@enderror</div>
          <div class="field"><label for="wg_allowed_ips">AllowedIPs در فایل مشتری</label><input id="wg_allowed_ips" name="wg_allowed_ips" class="input ltr" value="{{ old('wg_allowed_ips', $g->wg_allowed_ips) }}"><div class="help">پیش‌فرض: همه ترافیک از تونل.</div>@error('wg_allowed_ips')<div class="error">{{ $message }}</div>@enderror</div>
          <div class="field"><label for="wg_mtu">MTU (اختیاری)</label><input id="wg_mtu" name="wg_mtu" type="number" min="1280" max="1500" class="input ltr" value="{{ old('wg_mtu', $g->wg_mtu) }}" placeholder="1420"></div>
          <div class="field"><label for="wg_keepalive">PersistentKeepalive (ثانیه)</label><input id="wg_keepalive" name="wg_keepalive" type="number" min="0" max="300" class="input ltr" value="{{ old('wg_keepalive', $g->wg_keepalive) }}"></div>
        </div>
      </section>
    @else
      <section class="card step">
        <header class="step-head"><span class="step-no">۲</span><div><h2>پروفایل PPP</h2><p class="muted small">آدرس‌دهی (Pool)، سرعت و DNS اکانت‌های L2TP از پروفایل گرفته می‌شود.</p></div></header>
        <div class="form-grid">
          <div class="field"><label for="ppp_profile">پروفایل موجود روی روتر</label>
            <select id="ppp_profile" name="ppp_profile" class="input">
              @foreach($profiles as $p)
                <option value="{{ $p['name'] }}" @selected(old('ppp_profile', $g->ppp_profile) === $p['name'])>{{ $p['name'] }}{{ ! empty($p['remote-address']) ? ' — pool: '.$p['remote-address'] : ' — بدون Pool' }}{{ ! empty($p['rate-limit']) ? ' — سرعت '.$p['rate-limit'] : '' }}</option>
              @endforeach
            </select>@error('ppp_profile')<div class="error">{{ $message }}</div>@enderror
          </div>
          <div class="field"><label for="multi_login">حداکثر اتصال همزمان (پیش‌فرض گروه)</label><input id="multi_login" name="multi_login" type="number" min="1" max="50" class="input ltr" value="{{ old('multi_login', $g->multi_login) }}" placeholder="نامحدود"><div class="help">نشست‌های اضافه هر دقیقه قطع می‌شوند.</div></div>
          <div class="field"><label for="ipsec_psk">کلید IPsec برای نمایش به مشتری</label><input id="ipsec_psk" name="ipsec_psk" class="input ltr" maxlength="200" autocomplete="off" placeholder="{{ $g->ipsec_psk ? 'ثبت شده — برای تغییر وارد کنید' : 'خالی = خواندن از روتر' }}"><div class="help">همان IPsec Secret سرور L2TP روتر.</div></div>
        </div>
        <details class="adv mt-8" @if(old('new_profile')) open @endif>
          <summary class="muted">ساخت پروفایل جدید با محدودیت سرعت روی روتر</summary>
          <div class="form-grid mt-8">
            <div class="field"><label for="new_profile">نام پروفایل جدید</label><input id="new_profile" name="new_profile" class="input ltr" maxlength="60" value="{{ old('new_profile') }}" placeholder="panel-10M">@error('new_profile')<div class="error">{{ $message }}</div>@enderror</div>
            <div class="field"><label for="new_profile_rate">سرعت (آپلود/دانلود)</label><input id="new_profile_rate" name="new_profile_rate" class="input ltr" value="{{ old('new_profile_rate') }}" placeholder="10M/20M">@error('new_profile_rate')<div class="error">{{ $message }}</div>@enderror</div>
          </div>
          <div class="help">آدرس محلی و Pool از پروفایل انتخاب‌شده بالا کپی می‌شود.</div>
        </details>
      </section>
    @endif

    <section class="card step">
      <header class="step-head"><span class="step-no">۳</span><div><h2>پیش‌فرض‌ها</h2></div></header>
      <div class="form-grid">
        <div class="field"><label for="default_days">مدت پیش‌فرض (روز)</label><input id="default_days" name="default_days" type="number" min="1" max="3650" class="input ltr" value="{{ old('default_days', $g->default_days) }}"></div>
        <div class="field"><label for="default_volume_gb">حجم پیش‌فرض (گیگابایت)</label><input id="default_volume_gb" name="default_volume_gb" type="number" step="0.1" min="0.1" class="input ltr" value="{{ old('default_volume_gb', $g->default_volume_gb) }}" placeholder="نامحدود"></div>
        <div class="field"><label for="notes">یادداشت</label><input id="notes" name="notes" class="input" maxlength="500" value="{{ old('notes', $g->notes) }}"></div>
      </div>
      <label class="check"><input type="hidden" name="start_on_first_use" value="0"><input type="checkbox" name="start_on_first_use" value="1" @checked(old('start_on_first_use', $g->start_on_first_use))> مدت اعتبار از اولین اتصال شمرده شود</label>
      <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $g->is_active))> گروه فعال است</label>
    </section>

    <div class="submit-bar"><div class="muted small">ذخیره، اطلاعات لازم را از روتر می‌خواند؛ روتر باید در دسترس باشد.</div><button class="btn btn-lg" type="submit">ذخیره گروه</button></div>
  </form>
</x-layouts.app>
