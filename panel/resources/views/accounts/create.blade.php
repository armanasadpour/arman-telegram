<x-layouts.app title="ساخت اکانت">
  @php($manualFirst = $packages->isEmpty() || old('group') !== null)
  <div class="page-head">
    <div>
      <h1>ساخت اکانت جدید</h1>
      <p class="muted">اکانت OpenVPN مستقیم در سرور ساخته می‌شود و نام کاربری و رمز فقط یک بار نمایش داده می‌شود.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a>
  </div>

  <div class="create-wrap">
    {{-- mode switch: pure CSS (radio + sibling panels) --}}
    <input class="seg-input" type="radio" name="create_mode" id="mode_pkg" @checked(! $manualFirst) @disabled($packages->isEmpty())>
    <input class="seg-input" type="radio" name="create_mode" id="mode_manual" @checked($manualFirst)>
    <div class="seg" role="tablist" aria-label="روش ساخت">
      <label for="mode_pkg" @class(['seg-btn', 'is-disabled' => $packages->isEmpty()])><x-icon name="box"/><span>از روی بسته</span><small>سریع و با قیمت ثبت‌شده</small></label>
      <label for="mode_manual" class="seg-btn"><x-icon name="settings"/><span>ساخت دستی</span><small>انتخاب گروه و تنظیمات دلخواه</small></label>
    </div>

    {{-- ============ from package ============ --}}
    <div class="seg-panel panel-pkg">
      @if($packages->isEmpty())
        <div class="card"><div class="empty"><x-icon name="box"/><div>هنوز بسته‌ای برای اکانت جدید تعریف نشده است.</div></div></div>
      @else
      <form method="post" action="{{ route('accounts.store-package') }}" class="create-form">
        @csrf
        <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
        <section class="card step">
          <div class="field">
            <label for="pkg_customer">👤 نام کاربر <span class="text-danger">*</span></label>
            <input id="pkg_customer" name="comment" class="input" required maxlength="100" value="{{ ! $manualFirst ? old('comment') : '' }}" placeholder="مثلاً علی رضایی" autocomplete="off">
            @error('comment')<div class="error">{{ $message }}</div>@enderror
          </div>
        </section>

        <section class="card step">
          <header class="step-head"><span class="step-no">۱</span><div><h2>انتخاب بسته</h2><p class="muted small">گروه سرور، مدت، حجم و تعداد اتصال از بسته گرفته و قیمت لحظه ساخت ثبت می‌شود.</p></div></header>
          <div class="picks">
            @foreach($packages as $i => $row)
              @php($p = $row['p'])
              <label class="pick">
                <input class="pick-input" type="radio" name="package_id" value="{{ $p->id }}" required @checked(old('package_id') ? (int) old('package_id') === $p->id : $i === 0)>
                <span class="pick-body">
                  <span class="pick-check" aria-hidden="true">✓</span>
                  <span class="pick-title">{{ $p->name }}</span>
                  <span class="pick-specs">
                    <span><x-icon name="activity"/>{{ $p->duration_days ? fa($p->duration_days).' روز' : 'بدون محدودیت زمان' }}</span>
                    <span><x-icon name="layers"/>{{ $p->hasVolume() ? fa_bytes((int) ((float) $p->volume_gb * 1073741824)) : 'حجم نامحدود' }}</span>
                    @if($p->multi_login)<span><x-icon name="users"/>{{ fa($p->multi_login) }} کاربره</span>@endif
                  </span>
                  <span class="pick-price">
                    @if($row['q']->finalPrice < $p->price_rial)<s class="muted small">{{ fa_money($p->price_rial) }}</s>@endif
                    <strong>{{ fa_money($row['q']->finalPrice) }}</strong>
                  </span>
                </span>
              </label>
            @endforeach
          </div>
        </section>

        <section class="card step">
          <header class="step-head"><span class="step-no">۲</span><div><h2>نام کاربری و رمز</h2><p class="muted small">هر دو اختیاری‌اند؛ خالی بمانند خودکار ساخته می‌شوند.</p></div></header>
          <div class="form-grid">
            @include('accounts._credentials', ['prefix' => 'pkg_', 'withOld' => ! $manualFirst])
          </div>
        </section>

        <div class="submit-bar">
          <div class="muted small"><x-icon name="key"/> رمز پس از ساخت فقط یک بار نمایش داده می‌شود؛ آن را برای مشتری کپی کنید.</div>
          <button class="btn btn-lg" type="submit"><x-icon name="box"/>ساخت اکانت از بسته</button>
        </div>
      </form>
      @endif
    </div>

    {{-- ============ manual ============ --}}
    <div class="seg-panel panel-manual">
      @if($groups->isEmpty())
        <div class="alert alert-warning">هیچ گروهی از سرور همگام نشده است. @can('ibs_groups.view')<a href="{{ route('ibs.groups') }}">گروه‌ها را همگام‌سازی کنید.</a>@endcan</div>
      @else
      <form method="post" action="{{ route('accounts.store') }}" class="create-form">
        @csrf
        <input type="hidden" name="job_key" value="{{ old('job_key', $jobKey) }}">
        <section class="card step">
          <div class="field">
            <label for="customer">👤 نام کاربر <span class="text-danger">*</span></label>
            <input id="customer" name="comment" class="input" required maxlength="100" value="{{ $manualFirst ? old('comment') : '' }}" placeholder="مثلاً علی رضایی" autocomplete="off">
            @error('comment')<div class="error">{{ $message }}</div>@enderror
          </div>
        </section>

        <section class="card step">
          <header class="step-head"><span class="step-no">۱</span><div><h2>گروه سرور</h2><p class="muted small">مدت اعتبار، تعداد اتصال و تعرفه از گروه گرفته می‌شود.</p></div></header>
          <div class="picks picks-sm">
            @foreach($groups as $i => $g)
              <label class="pick">
                <input class="pick-input" type="radio" name="group" value="{{ $g->name }}" required @checked(old('group') ? old('group') === $g->name : $i === 0)>
                <span class="pick-body">
                  <span class="pick-check" aria-hidden="true">✓</span>
                  <span class="pick-title ltr"><bdi>{{ $g->name }}</bdi></span>
                  <span class="pick-specs">
                    <span><x-icon name="activity"/>{{ $g->rel_exp_seconds ? fa_duration($g->rel_exp_seconds) : 'بدون مدت' }}</span>
                    @if($g->multi_login !== null)<span><x-icon name="users"/>{{ fa($g->multi_login) }} کاربره</span>@endif
                  </span>
                  <span @class(['badge', 'badge-primary' => $g->charge?->isVolume()])>{{ $g->charge?->isVolume() ? 'حجمی' : 'بدون محدودیت حجم' }}</span>
                </span>
              </label>
            @endforeach
          </div>
        </section>

        <section class="card step">
          <header class="step-head"><span class="step-no">۲</span><div><h2>نام کاربری و رمز</h2><p class="muted small">نام کاربری: حروف کوچک انگلیسی، عدد، نقطه، خط تیره، زیرخط. رمز: هر چیزی.</p></div></header>
          <div class="form-grid">
            @include('accounts._credentials', ['prefix' => '', 'withOld' => $manualFirst])
          </div>
        </section>

        <details class="card step adv" @if(old('days') || old('multi_login') || old('volume_mb')) open @endif>
          <summary class="step-head"><span class="step-no">۳</span><div><h2>تنظیمات اختصاصی <span class="muted small">(اختیاری)</span></h2><p class="muted small">فقط اگر این اکانت باید با گروه فرق داشته باشد.</p></div><span class="adv-toggle" aria-hidden="true">⌄</span></summary>
          <div class="form-grid">
            <div class="field">
              <label for="days">مدت اعتبار (روز)</label>
              <div class="input-group"><input id="days" name="days" type="number" min="1" max="3650" class="input ltr" value="{{ old('days') }}" placeholder="مدت گروه"><span class="addon">روز</span></div>
              <div class="chips">@foreach([30, 60, 90, 180, 365] as $d)<button type="button" class="chip" data-set="#days" data-value="{{ $d }}">{{ fa($d) }}</button>@endforeach</div>
              <div class="help">از اولین اتصال شمرده می‌شود.</div>
            </div>
            <div class="field">
              <label for="multi_login">اتصال همزمان</label>
              <div class="input-group"><input id="multi_login" name="multi_login" type="number" min="1" max="255" class="input ltr" value="{{ old('multi_login') }}" placeholder="مقدار گروه"><span class="addon">دستگاه</span></div>
              <div class="chips">@foreach([1, 2, 3, 5] as $m)<button type="button" class="chip" data-set="#multi_login" data-value="{{ $m }}">{{ fa($m) }}</button>@endforeach</div>
            </div>
            <div class="field">
              <label for="volume_mb">حجم — فقط برای گروه حجمی</label>
              <div class="input-group"><input id="volume_mb" name="volume_mb" type="number" min="1" class="input ltr" value="{{ old('volume_mb') }}" placeholder="نامحدود"><span class="addon">مگابایت</span></div>
              <div class="chips">@foreach([5, 10, 20, 50, 100] as $gb)<button type="button" class="chip" data-set="#volume_mb" data-value="{{ $gb * 1024 }}">{{ fa($gb) }} گیگ</button>@endforeach</div>
            </div>
          </div>
        </details>

        <div class="submit-bar">
          <div class="muted small"><x-icon name="key"/> رمز پس از ساخت فقط یک بار نمایش داده می‌شود؛ آن را برای مشتری کپی کنید.</div>
          <button class="btn btn-lg" type="submit"><x-icon name="user"/>ساخت اکانت در سرور</button>
        </div>
      </form>
      @endif
    </div>
  </div>
</x-layouts.app>
