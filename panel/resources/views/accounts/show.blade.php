<x-layouts.app title="اکانت {{ $a->username }}">
  <div class="page-head">
    <div>
      <h1><bdi class="ltr">{{ $a->username }}</bdi></h1>
      <p class="muted">
        آخرین به‌روزرسانی از سرور: {{ $a->last_synced_at ? jdate($a->last_synced_at, 'Y/m/d H:i:s').' ('.fa_ago($a->last_synced_at).')' : 'هرگز' }}
      </p>
    </div>
    <div class="actions">
      <form method="post" action="{{ route('accounts.refresh', $a) }}">@csrf<button class="btn btn-secondary" type="submit">به‌روزرسانی از سرور</button></form>
      <a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a>
    </div>
  </div>

  @if(session('new_password') && ! session('changed_password'))
    <div class="alert alert-success">رمز اکانت: <strong><bdi class="ltr">{{ session('new_password') }}</bdi></strong> — این رمز فقط همین یک بار نمایش داده می‌شود؛ آن را به مشتری تحویل دهید.</div>
  @endif
  @if(session('revealed_password'))
    <div class="alert alert-warning">رمز فعلی (خوانده‌شده از سرور): <strong><bdi class="ltr">{{ session('revealed_password') }}</bdi></strong> — مشاهده رمز در گزارش رویدادها ثبت شد.</div>
  @endif
  @if($a->isStale())
    <div class="alert alert-warning">این اطلاعات قدیمی است؛ برای دیدن وضعیت لحظه‌ای «به‌روزرسانی از سرور» را بزنید.</div>
  @endif
  @if($a->sync_error)
    <div class="alert alert-danger">آخرین خطای همگام‌سازی: {{ $a->sync_error }}</div>
  @endif

  @php
    $rem = $a->remainingBytes(); $used = $a->usedBytes(); $total = $a->volume_total_bytes;
    $pct = ($total && $used !== null) ? min(100, (int) round($used * 100 / max(1, $total))) : null;
    $rel = $a->effectiveRelExpSeconds();
  @endphp
  <div class="grid stats mb-16">
    <div class="card stat"><div class="label">وضعیت</div><div class="value small">@include('accounts._status', ['a' => $a])</div></div>
    @include('accounts._conncard', ['online' => (bool) $a->is_online, 'main' => $a->is_online ? 'آنلاین' : 'آفلاین',
      'hint' => 'حداکثر همزمان: '.($a->effectiveMultiLogin() !== null ? fa($a->effectiveMultiLogin()) : '—'),
      'hint2' => 'نشست‌های آنلاین: '.fa((int) $a->online_sessions),
      'kickUrl' => ! $a->ibs_deleted_at && auth()->user()->can('accounts.update') ? route('accounts.kick', $a) : null])
  </div>
  @include('accounts._meters', ['a' => $a, 'class' => 'mb-16'])
  <div class="sheet-tiles" data-sheet-tiles></div>

  <div class="grid two">
    <div class="card" data-sheet="اطلاعات حساب" data-sheet-icon="ℹ️">
      <h2>اطلاعات</h2>
      <dl class="kv">
        <dt>شناسه سرور</dt><dd><bdi class="ltr">{{ $a->ibs_user_id }}</bdi></dd>
        <dt>گروه</dt><dd><bdi>{{ $a->group_name }}</bdi> @if($a->group?->charge_name)<span class="muted">(تعرفه {{ $a->group->charge_name }})</span>@endif</dd>
        <dt>مدت اعتبار</dt><dd>{{ fa_duration($rel) }} @if($a->rel_exp_seconds)<span class="badge">مختص این اکانت</span>@endif</dd>
        <dt>اولین اتصال</dt><dd>{{ $a->first_login_ibs ? jdate_local($a->first_login_ibs).' (به ساعت سرور)' : 'هنوز وصل نشده' }}</dd>
        <dt>ساخت در سرور</dt><dd>{{ $a->ibs_created_at ? jdate_local($a->ibs_created_at).' (به ساعت سرور)' : '—' }}</dd>
        <dt>منبع</dt><dd>{{ $a->source === 'panel' ? 'ساخته‌شده در پنل'.($a->creator ? ' توسط '.$a->creator->name : '') : 'وارد‌شده از سرور' }}</dd>
        <dt>👤 نام کاربر</dt><dd>{{ $a->displayComment() ?? "—" }}</dd>
        @if($a->locked)<dt>دلیل قفل</dt><dd>{{ $a->lock_reason }}</dd>@endif
      </dl>
    </div>
    <div class="card" data-sheet="نشست‌های آنلاین" data-sheet-icon="🟢">
      <h2>نشست‌های آنلاین</h2>
      @if(! $a->is_online || empty($a->sessions))
        <div class="empty"><div>در حال حاضر آنلاین نیست.</div></div>
      @else
        <div class="table-wrap"><table class="table">
          <thead><tr><th>سرور</th><th>مدت</th><th>دانلود</th><th>آپلود</th></tr></thead>
          <tbody>
          @foreach($a->sessions as $s)
            <tr>
              <td><bdi class="ltr">{{ $s['ras_ip'] ?? '—' }}</bdi></td>
              <td>{{ isset($s['duration_secs']) ? fa_duration((int) $s['duration_secs']) : '—' }}</td>
              <td>{{ isset($s['out_bytes']) ? fa_bytes((int) $s['out_bytes']) : '—' }}</td>
              <td>{{ isset($s['in_bytes']) ? fa_bytes((int) $s['in_bytes']) : '—' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table></div>
        <p class="help muted">از دید سرور: «دانلود» یعنی داده ارسال‌شده به کاربر.</p>
      @endif
    </div>
  </div>

  @include('accounts._connlog', ['key' => 'v:'.$a->id])

  @unless($a->ibs_deleted_at)
    @can('accounts.update')
      <div class="grid two">
        <div class="card" data-sheet="ویرایش اطلاعات حساب" data-sheet-icon="✏️">
          <h2>ویرایش اطلاعات حساب</h2>
          <form method="post" action="{{ route('accounts.info', $a) }}">@csrf
            <div class="field"><label for="edit_customer">👤 نام کاربر</label><input id="edit_customer" name="comment" class="input" required maxlength="100" value="{{ $a->displayComment() }}" autocomplete="off"></div>
            @php($sameKind = \App\Models\IbsGroup::query()->with('charge')->where('present', true)->orderBy('name')->get()->filter(fn ($g) => (bool) $g->charge?->isVolume() === $a->isVolumeLimited()))
            <div class="field"><label for="edit_group">گروه</label>
              <select id="edit_group" name="group" class="input">
                @foreach($sameKind as $g)<option value="{{ $g->name }}" @selected($g->name === $a->group_name)>{{ $g->name }}{{ $g->rel_exp_seconds ? ' — '.fa_duration($g->rel_exp_seconds) : '' }}</option>@endforeach
              </select>
              <div class="help">فقط گروه‌های هم‌نوع ({{ $a->isVolumeLimited() ? 'حجمی' : 'بدون محدودیت حجم' }}). مدت و تعداد اتصال از گروه جدید گرفته می‌شود مگر برای اکانت جدا تعیین شده باشد.</div>
            </div>
            <button class="btn" type="submit">ذخیره</button>
          </form>
        </div>
        @if($applicable->isNotEmpty())
          <div class="card" data-sheet="اعمال بسته" data-sheet-icon="📦">
            <h2>اعمال بسته</h2>
            <form method="post" action="{{ route('accounts.apply-package', $a) }}" data-confirm="بسته روی این اکانت اعمال شود؟">
              @csrf
              <div class="field"><label for="apply_pkg">بسته تمدید / افزایش حجم</label>
                <select id="apply_pkg" name="package_id" class="input" required>
                  @foreach($applicable as $row)<option value="{{ $row['p']->id }}">{{ $row['p']->name }} — {{ $row['p']->summary() }} — {{ fa_money($row['q']->finalPrice) }}</option>@endforeach
                </select></div>
              <button class="btn" type="submit">اعمال بسته</button>
            </form>
          </div>
        @endif
        <div class="card" data-sheet="تمدید دستی" data-sheet-icon="📅">
          <h2>تمدید دستی</h2>
          <p class="muted">سیاست فعلی: {{ $renewalMode === 'smart' ? 'اگر فعال است از انقضای فعلی، اگر منقضی شده از الان' : 'همیشه از تاریخ انقضای فعلی' }}.</p>
          <form method="post" action="{{ route('accounts.renew', $a) }}" data-confirm="اکانت تمدید شود؟">
            @csrf
            <div class="form-grid">
              <div class="field"><label for="days">تعداد روز</label><input id="days" name="days" type="number" min="1" max="3650" class="input ltr" value="30" required></div>
            </div>
            <button class="btn" type="submit">تمدید</button>
          </form>
        </div>
        @if($a->isVolumeLimited())
          <div class="card" data-sheet="افزایش حجم" data-sheet-icon="📶">
            <h2>افزایش حجم دستی</h2>
            <form method="post" action="{{ route('accounts.volume', $a) }}" data-confirm="حجم اضافه شود؟">
              @csrf
              <div class="form-grid">
                <div class="field"><label for="gb">گیگابایت</label><input id="gb" name="gb" type="number" step="0.1" min="0.1" class="input ltr" value="5" required></div>
              </div>
              <button class="btn" type="submit">افزودن حجم</button>
            </form>
          </div>
        @endif
        <div class="card" data-sheet="بازنشانی تاریخ و حجم" data-sheet-icon="↺">
          <h2>بازنشانی تاریخ و حجم</h2>
          <p class="muted small">اکانت مثل روز اول می‌شود: مدت اعتبار ({{ fa_duration($a->effectiveRelExpSeconds()) }}) از اولین اتصال بعدی شروع می‌شود{{ $a->isVolumeLimited() && $a->volume_total_bytes ? ' و حجم به '.fa_bytes((int) $a->volume_total_bytes).' برمی‌گردد' : '' }}.</p>
          <form method="post" action="{{ route('accounts.reset-first', $a) }}" data-confirm="تاریخ و حجم «{{ $a->username }}» بازنشانی شود و از اولین اتصال دوباره شروع شود؟">
            @csrf
            <button class="btn btn-secondary" type="submit">↺ بازنشانی با اولین اتصال</button>
          </form>
        </div>
        <div class="card" data-sheet="{{ $a->locked ? 'فعال‌سازی دوباره' : 'غیرفعال‌سازی (قفل)' }}" data-sheet-icon="⛔" data-sheet-tone="danger">
          <h2>{{ $a->locked ? 'فعال‌سازی دوباره' : 'غیرفعال‌سازی (قفل)' }}</h2>
          <form method="post" action="{{ route('accounts.lock', $a) }}" data-confirm="{{ $a->locked ? 'قفل برداشته شود؟' : 'اکانت قفل شود؟ کاربر دیگر نمی‌تواند وصل شود.' }}">
            @csrf
            <button class="btn {{ $a->locked ? '' : 'btn-danger' }}" type="submit">{{ $a->locked ? 'برداشتن قفل' : 'قفل کن' }}</button>
          </form>
        </div>
        <div class="card" data-sheet="تغییر رمز" data-sheet-icon="🔑" @if(session('changed_password')) data-sheet-pw="{{ session('changed_password') }}" @endif>
          <h2>تغییر رمز</h2>
          <form method="post" action="{{ route('accounts.password', $a) }}" data-confirm="رمز اکانت تغییر کند؟">
            @csrf
            <div class="field"><label for="new_pw">رمز جدید (خالی = تولید خودکار)</label><input id="new_pw" name="password" class="input ltr" maxlength="64" autocomplete="off"></div>
            <button class="btn btn-secondary" type="submit">تغییر رمز</button>
          </form>
        </div>
      </div>
    @endcan
    <div class="grid two">
      @can('accounts.sensitive')
        <div class="card" data-sheet="مشاهده رمز فعلی" data-sheet-icon="👁">
          <h2>مشاهده رمز فعلی</h2>
          <p class="muted">رمز از سرور خوانده می‌شود (پنل آن را ذخیره نمی‌کند). عملیات حساس است و ثبت می‌شود.</p>
          <form method="post" action="{{ route('accounts.reveal', $a) }}">@csrf<button class="btn btn-secondary" type="submit">نمایش رمز</button></form>
        </div>
      @endcan
      @can('accounts.delete')
        <div class="card card-danger" data-sheet="حذف از سرور" data-sheet-icon="🗑" data-sheet-tone="danger">
          <h2 class="text-danger">حذف از سرور</h2>
          <p class="muted">اکانت از سرور حذف می‌شود؛ سوابق اتصال در سرور باقی می‌ماند. برگشت‌پذیر نیست.</p>
          <form method="post" action="{{ route('accounts.destroy', $a) }}" data-confirm="اکانت «{{ $a->username }}» برای همیشه از سرور حذف شود؟">
            @csrf @method('delete')
            <button class="btn btn-danger" type="submit">حذف اکانت</button>
          </form>
        </div>
      @endcan
    </div>
  @endunless
</x-layouts.app>
