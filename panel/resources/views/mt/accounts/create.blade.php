@php($pl = ['l2tp' => 'L2TP', 'wireguard' => 'WireGuard'][$proto ?? ''] ?? 'L2TP / WireGuard')
<x-layouts.app :title="'ساخت اکانت '.$pl">
  @php($manualFirst = $packages->isEmpty() || old('mt_group_id') !== null)
  <div class="page-head">
    <div>
      <h1>ساخت اکانت {{ $pl }}</h1>
      <p class="muted">اکانت مستقیم روی روتر میکروتیک ساخته می‌شود؛ حجم، روز و تعداد اتصال را پنل کنترل می‌کند.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route($proto === 'wireguard' ? 'wg.accounts' : ($proto === 'l2tp' ? 'l2tp.accounts' : 'mt.accounts.index')) }}">بازگشت</a>
  </div>

  @if($groups->isEmpty())
    <div class="card"><div class="empty"><x-icon name="layers"/><div>هیچ گروه فعالی نیست. ابتدا روی روتر L2TP یا WireGuard را فعال و یک گروه بسازید.</div>
      @can('routers.view')<a class="btn mt-8" href="{{ route($proto === 'wireguard' ? 'wg.groups' : 'l2tp.groups') }}">گروه‌ها</a>@endcan</div></div>
  @else
  <div class="create-wrap">
    <input class="seg-input" type="radio" name="create_mode" id="mode_pkg" @checked(! $manualFirst) @disabled($packages->isEmpty())>
    <input class="seg-input" type="radio" name="create_mode" id="mode_manual" @checked($manualFirst)>
    <div class="seg" role="tablist" aria-label="روش ساخت">
      <label for="mode_pkg" @class(['seg-btn', 'is-disabled' => $packages->isEmpty()])><x-icon name="box"/><span>از روی بسته</span><small>با قیمت ثبت‌شده</small></label>
      <label for="mode_manual" class="seg-btn"><x-icon name="settings"/><span>حجم و روز دلخواه</span><small>انتخاب گروه و مقادیر</small></label>
    </div>

    <div class="seg-panel panel-pkg">
      @if($packages->isEmpty())
        <div class="card"><div class="empty"><x-icon name="box"/><div>هنوز بسته میکروتیکی تعریف نشده است (بسته‌ها → پروتکل: میکروتیک).</div></div></div>
      @else
      <form method="post" action="{{ route('mt.accounts.store-package') }}" class="create-form">
        @csrf
        <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
        <section class="card step">
          <header class="step-head"><span class="step-no">۱</span><div><h2>انتخاب بسته</h2></div></header>
          <div class="picks">
            @foreach($packages as $i => $row)
              @php($p = $row['p'])
              <label class="pick">
                <input class="pick-input" type="radio" name="package_id" value="{{ $p->id }}" required @checked(old('package_id') ? (int) old('package_id') === $p->id : $i === 0)>
                <span class="pick-body">
                  <span class="pick-check" aria-hidden="true">✓</span>
                  <span class="pick-title">{{ $p->name }}</span>
                  <span class="pick-specs">
                    <span><x-icon name="link"/>{{ $p->mtGroup->protocolLabel() }}</span>
                    <span><x-icon name="activity"/>{{ $p->duration_days ? fa($p->duration_days).' روز' : 'بدون محدودیت زمان' }}</span>
                    <span><x-icon name="layers"/>{{ $p->hasVolume() ? fa_bytes((int) ((float) $p->volume_gb * 1073741824)) : 'حجم نامحدود' }}</span>
                  </span>
                  <span class="pick-price">@if($row['q']->finalPrice < $p->price_rial)<s class="muted small">{{ fa_money($p->price_rial) }}</s>@endif<strong>{{ fa_money($row['q']->finalPrice) }}</strong></span>
                </span>
              </label>
            @endforeach
          </div>
        </section>
        <section class="card step">
          <header class="step-head"><span class="step-no">۲</span><div><h2>نام کاربری و رمز</h2><p class="muted small">اختیاری؛ رمز فقط برای L2TP است (WireGuard کلید می‌گیرد).</p></div></header>
          <div class="form-grid">
            @include('accounts._credentials', ['prefix' => 'pkg_', 'withOld' => ! $manualFirst])
            <div class="field"><label for="pkg_comment">توضیح (اختیاری)</label><input id="pkg_comment" name="comment" class="input" maxlength="100" value="{{ ! $manualFirst ? old('comment') : '' }}" placeholder="نام مشتری یا شماره سفارش"></div>
          </div>
        </section>
        <div class="submit-bar"><div class="muted small"><x-icon name="key"/> بعد از ساخت، اطلاعات اتصال (و QR برای WireGuard) نمایش داده می‌شود.</div><button class="btn btn-lg" type="submit"><x-icon name="box"/>ساخت از بسته</button></div>
      </form>
      @endif
    </div>

    <div class="seg-panel panel-manual">
      <form method="post" action="{{ route('mt.accounts.store') }}" class="create-form">
        @csrf
        <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
        <section class="card step">
          <header class="step-head"><span class="step-no">۱</span><div><h2>گروه و پروتکل</h2></div></header>
          <div class="picks picks-sm">
            @foreach($groups as $i => $g)
              <label class="pick">
                <input class="pick-input" type="radio" name="mt_group_id" value="{{ $g->id }}" required @checked(old('mt_group_id') ? (int) old('mt_group_id') === $g->id : $i === 0)
                  data-days="{{ $g->default_days }}" data-gb="{{ $g->default_volume_gb !== null ? (float) $g->default_volume_gb : '' }}">
                <span class="pick-body">
                  <span class="pick-check" aria-hidden="true">✓</span>
                  <span class="pick-title">{{ $g->name }}</span>
                  <span class="pick-specs">
                    <span><x-icon name="link"/>{{ $g->protocolLabel() }}</span>
                    <span><x-icon name="settings"/>{{ $g->router->name }}</span>
                    @if($g->isWireguard())<span><x-icon name="users"/>{{ fa($g->multi_login ?? 1) }} دستگاه</span>@elseif($g->multi_login)<span><x-icon name="users"/>{{ fa($g->multi_login) }} کاربره</span>@endif
                  </span>
                  <span @class(['badge', 'badge-primary' => $g->isWireguard()])>{{ $g->isWireguard() ? 'فایل + QR' : 'نام کاربری + رمز' }}</span>
                </span>
              </label>
            @endforeach
          </div>
        </section>
        <section class="card step">
          <header class="step-head"><span class="step-no">۲</span><div><h2>حجم و مدت</h2><p class="muted small">خالی = نامحدود.</p></div></header>
          <div class="form-grid">
            <div class="field">
              <label for="days">مدت اعتبار</label>
              <div class="input-group"><input id="days" name="days" type="number" min="1" max="3650" class="input ltr" value="{{ old('days', 30) }}" placeholder="نامحدود"><span class="addon">روز</span></div>
              <div class="chips">@foreach([7, 30, 60, 90, 180, 365] as $d)<button type="button" class="chip" data-set="#days" data-value="{{ $d }}">{{ fa($d) }}</button>@endforeach</div>
              @error('days')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
              <label for="volume_gb">حجم</label>
              <div class="input-group"><input id="volume_gb" name="volume_gb" type="number" step="0.1" min="0.1" class="input ltr" value="{{ old('volume_gb') }}" placeholder="نامحدود"><span class="addon">گیگابایت</span></div>
              <div class="chips">@foreach([5, 10, 20, 30, 50, 100] as $gb)<button type="button" class="chip" data-set="#volume_gb" data-value="{{ $gb }}">{{ fa($gb) }}</button>@endforeach<button type="button" class="chip" data-set="#volume_gb" data-value="">نامحدود</button></div>
              @error('volume_gb')<div class="error">{{ $message }}</div>@enderror
            </div>
            <div class="field">
              <label for="multi_login">تعداد کاربر / دستگاه</label>
              <div class="input-group"><input id="multi_login" name="multi_login" type="number" min="1" max="50" class="input ltr" value="{{ old('multi_login') }}" placeholder="مقدار گروه"><span class="addon">دستگاه</span></div>
              <div class="help">L2TP: اتصال همزمان. WireGuard: هر دستگاه فایل و QR جدا (حداکثر ۱۰).</div>
              <div class="chips">@foreach([1, 2, 3, 5] as $m)<button type="button" class="chip" data-set="#multi_login" data-value="{{ $m }}">{{ fa($m) }}</button>@endforeach</div>
            </div>
          </div>
        </section>
        <section class="card step">
          <header class="step-head"><span class="step-no">۳</span><div><h2>نام کاربری و رمز</h2><p class="muted small">اختیاری؛ خالی = خودکار.</p></div></header>
          <div class="form-grid">
            @include('accounts._credentials', ['prefix' => '', 'withOld' => $manualFirst])
            <div class="field"><label for="comment">توضیح (اختیاری)</label><input id="comment" name="comment" class="input" maxlength="150" value="{{ $manualFirst ? old('comment') : '' }}" placeholder="نام مشتری یا شماره سفارش"></div>
          </div>
        </section>
        <div class="submit-bar"><div class="muted small"><x-icon name="key"/> بعد از ساخت، اطلاعات اتصال نمایش داده می‌شود.</div><button class="btn btn-lg" type="submit"><x-icon name="user"/>ساخت اکانت روی روتر</button></div>
      </form>
    </div>
  </div>
  @endif
</x-layouts.app>
