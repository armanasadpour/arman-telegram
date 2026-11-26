@php($lbl = $a->statusLabel())
<x-layouts.app :title="'اکانت '.$a->username">
  <div class="page-head">
    <div>
      <h1><bdi class="ltr">{{ $a->username }}</bdi> <span class="badge badge-{{ $lbl[1] }}">{{ $lbl[0] }}</span></h1>
      <p class="muted">{{ $a->protocolLabel() }} · گروه {{ $a->group?->name }} · روتر {{ $a->router?->name }}
        — به‌روزرسانی: {{ $a->last_synced_at ? fa_ago($a->last_synced_at) : 'هنوز نه' }}</p>
    </div>
    <div class="actions">
      @unless($a->trashed())
        <form method="post" action="{{ route('mt.accounts.sync', $a) }}">@csrf<button class="btn btn-secondary" type="submit">به‌روزرسانی از روتر</button></form>
        @can('mt_accounts.sensitive')
          @if(! $reveal)
            <a class="btn btn-secondary" href="{{ route('mt.accounts.connect', $a) }}">{{ $a->isWireguard() ? 'فایل و QR' : 'اطلاعات اتصال' }}</a>
          @endif
        @endcan
      @endunless
      <a class="btn btn-secondary" href="{{ $a->listUrl() }}">بازگشت</a>
    </div>
  </div>

  @if($a->error && ! $a->trashed())<div class="alert alert-{{ $a->status === 'failed' ? 'danger' : 'warning' }}">{{ $a->error }}</div>@endif
  @if($a->status === 'pending')
    <div class="alert alert-warning">اکانت هنوز روی روتر ساخته نشده است؛ هر دقیقه خودکار دوباره تلاش می‌شود.
      @can('mt_accounts.create')<form method="post" action="{{ route('mt.accounts.retry', $a) }}" class="inline">@csrf<button class="btn btn-secondary btn-sm" type="submit">تلاش همین الان</button></form>@endcan</div>
  @elseif($a->status === 'failed')
    @can('mt_accounts.create')<div class="alert alert-danger">ساخت روی روتر ناموفق بود. مشکل را برطرف و دوباره تلاش کنید یا اکانت را حذف کنید.
      <form method="post" action="{{ route('mt.accounts.retry', $a) }}" class="inline">@csrf<button class="btn btn-secondary btn-sm" type="submit">تلاش دوباره</button></form></div>@endcan
  @endif

  @if($reveal)
    @include('mt.accounts._connect', ['a' => $a])
  @endif

  <div class="grid stats mb-16">
    @include('accounts._conncard', ['online' => (bool) $a->is_online, 'main' => $a->is_online ? ($a->isWireguard() ? 'آنلاین' : fa($a->online_count).' نشست آنلاین') : 'آفلاین',
      'hint' => $a->online_ip ? 'از IP '.$a->online_ip : ($a->last_seen_at ? 'آخرین فعالیت '.fa_ago($a->last_seen_at) : 'هنوز وصل نشده'),
      'kickUrl' => ! $a->isWireguard() && ! $a->trashed() && auth()->user()->can('mt_accounts.update') ? route('mt.accounts.kick', $a) : null])
    <div class="card stat"><div class="label">مصرف کل</div><div class="value small">{{ fa_bytes($a->used_bytes) }}</div>
      <div class="hint">دانلود {{ fa_bytes($a->download_bytes) }} · آپلود {{ fa_bytes($a->upload_bytes) }}</div></div>
  </div>
  @include('mt.accounts._meters', ['a' => $a, 'class' => 'mb-16'])
  <div class="sheet-tiles" data-sheet-tiles></div>

  <div class="grid two">
    <div class="card" data-sheet="اطلاعات حساب" data-sheet-icon="ℹ️">
      <h2>اطلاعات</h2>
      <dl class="kv">
        <dt>پروتکل</dt><dd>{{ $a->protocolLabel() }}</dd>
        @if($a->isWireguard())<dt>دستگاه‌ها</dt><dd>{{ fa($a->deviceCount()) }} دستگاه</dd>
          @foreach($a->allDevices() as $dev)<dt>دستگاه {{ fa($dev->deviceNo()) }}</dt><dd><bdi class="ltr">{{ $dev->wg_address ?? '—' }}</bdi> @if($dev->is_online)<span class="badge badge-success">آنلاین</span>@elseif($dev->last_seen_at)<span class="muted small">{{ fa_ago($dev->last_seen_at) }}</span>@endif</dd>@endforeach
        @else<dt>اتصال همزمان</dt><dd>{{ ($a->multi_login ?? $a->group?->multi_login) ? fa($a->multi_login ?? $a->group->multi_login) : 'نامحدود' }}</dd>@endif
        <dt>مدت</dt><dd>{{ $a->duration_days ? fa($a->duration_days).' روز' : 'نامحدود' }}{{ $a->expires_at ? ' — تا '.jdate($a->expires_at, 'Y/m/d H:i') : ($a->duration_days ? ' (از اولین اتصال)' : '') }}</dd>
        <dt>حجم</dt><dd>{{ $a->isVolumeLimited() ? fa_bytes($a->volume_limit_bytes) : 'نامحدود' }}</dd>
        <dt>اولین اتصال</dt><dd>{{ $a->first_used_at ? jdate($a->first_used_at) : '—' }}</dd>
        <dt>ساخت</dt><dd>{{ jdate($a->created_at) }}{{ $a->creator ? ' توسط '.$a->creator->name : '' }}</dd>
        @if($a->package)<dt>بسته</dt><dd>{{ $a->package->name }}</dd>@endif
        @if($a->reseller)<dt>همکار</dt><dd>{{ $a->reseller->name }} ({{ $a->reseller->code }})</dd>@endif
        <dt>👤 نام کاربر</dt><dd>{{ $a->comment ?: '—' }}</dd>
      </dl>
    </div>
    <div class="card" data-sheet="مصرف ۳۰ روز اخیر" data-sheet-icon="📊">
      <h2>مصرف ۳۰ روز اخیر</h2>
      @if($usage->isEmpty())<div class="empty"><div>هنوز مصرفی ثبت نشده است.</div></div>@else<x-chart :values="$usageDays"/><p class="help muted">بر حسب مگابایت در روز.</p>@endif
    </div>
  </div>

  @include('accounts._connlog', ['key' => 'm:'.$a->id])

  @if(! $a->trashed() && ! in_array($a->status, ['pending', 'failed'], true))
    @can('mt_accounts.update')
      <div class="grid two">
        <div class="card" data-sheet="ویرایش اطلاعات حساب" data-sheet-icon="✏️">
          <h2>ویرایش اطلاعات حساب</h2>
          <form method="post" action="{{ route('mt.accounts.info', $a) }}">@csrf
            <div class="field"><label for="edit_customer">👤 نام کاربر</label><input id="edit_customer" name="comment" class="input" required maxlength="100" value="{{ $a->comment }}" autocomplete="off"></div>
            @php($sameGroups = \App\Models\MtGroup::query()->where('router_id', $a->router_id)->where('protocol', $a->protocol)->when($a->isWireguard(), fn ($q) => $q->where('wg_interface', $a->group?->wg_interface))->orderBy('name')->get())
            <div class="field"><label for="edit_group">گروه</label>
              <select id="edit_group" name="mt_group_id" class="input">
                @foreach($sameGroups as $g)<option value="{{ $g->id }}" @selected($g->id === $a->mt_group_id)>{{ $g->name }}{{ $g->isWireguard() ? ($g->rate_limit ? ' — سرعت '.$g->rate_limit : '') : ' — پروفایل '.$g->ppp_profile }}</option>@endforeach
              </select>
              <div class="help">{{ $a->isWireguard() ? 'گروه‌های همین اینترفیس؛ محدودیت سرعت گروه جدید اعمال می‌شود.' : 'پروفایل PPP (سرعت، آدرس) از اتصال بعدی اعمال می‌شود.' }}</div>
            </div>
            <button class="btn" type="submit">ذخیره</button>
          </form>
        </div>
        @if($renewals->isNotEmpty())
          <div class="card" data-sheet="اعمال بسته" data-sheet-icon="📦">
            <h2>اعمال بسته</h2>
            <form method="post" action="{{ route('mt.accounts.package', $a) }}" data-confirm="بسته روی این اکانت اعمال شود؟">@csrf
              <div class="field"><label for="pkg">بسته تمدید / افزایش حجم</label><select id="pkg" name="package_id" class="input">
                @foreach($renewals as $p)<option value="{{ $p->id }}">{{ $p->name }} — {{ $p->summary() }} — {{ fa_money($p->price_rial) }}</option>@endforeach</select></div>
              <button class="btn" type="submit">اعمال بسته</button>
            </form>
          </div>
        @endif
        @if($a->duration_days || $a->expires_at)
          <div class="card" data-sheet="تمدید دستی" data-sheet-icon="📅">
            <h2>تمدید</h2>
            <p class="muted small">اگر منقضی شده از الان، وگرنه به انتهای مدت فعلی اضافه می‌شود.</p>
            <form method="post" action="{{ route('mt.accounts.renew', $a) }}" data-confirm="تمدید شود؟">@csrf
              <div class="field"><div class="input-group"><input id="renew_days" name="days" type="number" min="1" max="3650" class="input ltr" value="30" required><span class="addon">روز</span></div>
                <div class="chips">@foreach([7, 30, 90] as $d)<button type="button" class="chip" data-set="#renew_days" data-value="{{ $d }}">{{ fa($d) }}</button>@endforeach</div></div>
              <button class="btn" type="submit">تمدید</button>
            </form>
          </div>
        @endif
        @if($a->isVolumeLimited())
          <div class="card" data-sheet="افزایش حجم" data-sheet-icon="📶">
            <h2>افزایش حجم</h2>
            <form method="post" action="{{ route('mt.accounts.volume', $a) }}" data-confirm="حجم اضافه شود؟">@csrf
              <div class="field"><div class="input-group"><input id="add_gb" name="gb" type="number" step="0.1" min="0.1" class="input ltr" value="10" required><span class="addon">گیگابایت</span></div>
                <div class="chips">@foreach([5, 10, 20, 50] as $gb)<button type="button" class="chip" data-set="#add_gb" data-value="{{ $gb }}">{{ fa($gb) }}</button>@endforeach</div></div>
              <button class="btn" type="submit">افزودن حجم</button>
            </form>
          </div>
        @endif
        <div class="card" data-sheet="بازنشانی تاریخ و حجم" data-sheet-icon="↺">
          <h2>بازنشانی تاریخ و حجم</h2>
          <p class="muted small">مصرف صفر می‌شود{{ $a->isVolumeLimited() ? ' (حجم کامل '.fa_bytes($a->volume_limit_bytes).')' : '' }}{{ $a->duration_days ? ' و '.fa($a->duration_days).' روز از اولین اتصال بعدی شروع می‌شود' : '' }}.</p>
          <form method="post" action="{{ route('mt.accounts.reset-first', $a) }}" data-confirm="تاریخ و حجم «{{ $a->username }}» بازنشانی شود و از اولین اتصال دوباره شروع شود؟">@csrf
            <button class="btn btn-secondary" type="submit">↺ بازنشانی با اولین اتصال</button>
          </form>
        </div>
        <div class="card" data-sheet="{{ $a->status === 'disabled' ? 'فعال‌سازی' : 'غیرفعال‌سازی' }}" data-sheet-icon="⛔" data-sheet-tone="danger">
          <h2>{{ $a->status === 'disabled' ? 'فعال‌سازی' : 'غیرفعال‌سازی' }}</h2>
          <form method="post" action="{{ route('mt.accounts.toggle', $a) }}" data-confirm="{{ $a->status === 'disabled' ? 'اکانت فعال شود؟' : 'اکانت غیرفعال و اتصال‌های فعلی قطع شود؟' }}">@csrf
            <button class="btn {{ $a->status === 'disabled' ? '' : 'btn-danger' }}" type="submit">{{ $a->status === 'disabled' ? 'فعال کن' : 'غیرفعال کن' }}</button>
          </form>
        </div>
        <div class="card" data-sheet="ویرایش محدودیت‌ها" data-sheet-icon="⚙️">
          <h2>ویرایش دستی محدودیت‌ها</h2>
          <form method="post" action="{{ route('mt.accounts.limits', $a) }}" class="mt-8" data-confirm="محدودیت‌ها تغییر کند؟">@csrf
            <div class="form-grid">
              <div class="field"><label for="days_left">روز باقی‌مانده (از الان)</label><input id="days_left" name="days_left" type="number" min="0" max="3650" class="input ltr" value="{{ $a->expires_at ? max(0, (int) ceil(now()->diffInSeconds($a->expires_at, false) / 86400)) : $a->duration_days }}" placeholder="نامحدود"></div>
              <div class="field"><label for="lim_gb">کل حجم (گیگابایت)</label><input id="lim_gb" name="volume_gb" type="number" step="0.1" min="0" class="input ltr" value="{{ $a->isVolumeLimited() ? round($a->volume_limit_bytes / 1073741824, 2) : '' }}" placeholder="نامحدود"></div>
              @if($a->isWireguard())<div class="field"><label for="lim_ml">تعداد دستگاه</label><input id="lim_ml" name="multi_login" type="number" min="1" max="10" class="input ltr" value="{{ $a->deviceCount() }}"></div>@endif
              @unless($a->isWireguard())<div class="field"><label for="lim_ml">اتصال همزمان</label><input id="lim_ml" name="multi_login" type="number" min="1" max="50" class="input ltr" value="{{ $a->multi_login }}" placeholder="مقدار گروه"></div>@endunless
            </div>
            <button class="btn btn-secondary" type="submit">ذخیره</button>
          </form>
          <form method="post" action="{{ route('mt.accounts.reset', $a) }}" class="mt-8" data-confirm="مصرف این اکانت صفر شود؟">@csrf
            <button class="btn btn-secondary" type="submit">صفر کردن مصرف</button>
          </form>
        </div>
      </div>
    @endcan
    <div class="grid two">
      @can('mt_accounts.sensitive')
        <div class="card" data-sheet="{{ $a->isWireguard() ? 'کلید جدید' : 'تغییر رمز' }}" data-sheet-icon="{{ $a->isWireguard() ? '🗝' : '🔑' }}" @if(session('changed_password')) data-sheet-pw="{{ session('changed_password') }}" @endif>
          @if($a->isWireguard() && ! $a->wg_private_key)
            <h2>ثبت کلید خصوصی مشتری</h2>
            <p class="muted small">خط «PrivateKey» از فایل WireGuard فعلی مشتری؛ با کلید عمومی روی روتر چک می‌شود.</p>
            <form method="post" action="{{ route('mt.accounts.private-key', $a) }}" class="mb-12">@csrf
              <div class="field"><input name="private_key" class="input ltr" maxlength="60" required autocomplete="off" placeholder="PrivateKey = ..."></div>
              <button class="btn btn-secondary" type="submit">ثبت کلید</button>
            </form>
          @endif
          @if($a->isWireguard())
            <h2>کلید جدید</h2>
            <p class="muted small">فایل و QR قبلی از کار می‌افتد؛ برای وقتی که فایل مشتری لو رفته یا روی دستگاه دیگری استفاده می‌شود.</p>
            <form method="post" action="{{ route('mt.accounts.keys', $a) }}" data-confirm="کلید جدید ساخته شود؟ فایل قبلی همان دستگاه کار نخواهد کرد.">@csrf
              @if($a->deviceCount() > 1)
                <div class="field"><select name="device" class="input" aria-label="دستگاه">@foreach($a->allDevices() as $dev)<option value="{{ $dev->deviceNo() }}">دستگاه {{ fa($dev->deviceNo()) }}</option>@endforeach</select></div>
              @endif
              <button class="btn btn-secondary" type="submit">ساخت کلید جدید</button></form>
          @else
            <h2>تغییر رمز</h2>
            <form method="post" action="{{ route('mt.accounts.password', $a) }}" data-confirm="رمز تغییر کند؟">@csrf
              <div class="field"><label for="new_pw">رمز جدید (خالی = تصادفی)</label><input id="new_pw" name="password" class="input ltr" maxlength="64" autocomplete="off"></div>
              <button class="btn btn-secondary" type="submit">تغییر رمز</button>
            </form>
          @endif
        </div>
      @endcan
      @can('mt_accounts.delete')
        <div class="card card-danger" data-sheet="حذف" data-sheet-icon="🗑" data-sheet-tone="danger">
          <h2 class="text-danger">حذف</h2>
          <p class="muted small">{{ $a->isWireguard() ? 'Peer' : 'کاربر PPP' }} از روتر حذف می‌شود و اتصال قطع می‌شود. سوابق مصرف و فروش در پنل می‌ماند.</p>
          <form method="post" action="{{ route('mt.accounts.destroy', $a) }}" data-confirm="اکانت «{{ $a->username }}» حذف شود؟">@csrf @method('delete')
            <label class="check"><input type="checkbox" name="panel_only" value="1"> فقط از پنل (روتر از دسترس خارج شده)</label>
            <button class="btn btn-danger" type="submit">حذف اکانت</button>
          </form>
        </div>
      @endcan
    </div>
  @elseif(in_array($a->status, ['failed', 'pending'], true) && ! $a->trashed())
    @can('mt_accounts.delete')
      <div class="card card-danger" data-sheet="حذف" data-sheet-icon="🗑" data-sheet-tone="danger">
        <h2 class="text-danger">حذف</h2>
        <form method="post" action="{{ route('mt.accounts.destroy', $a) }}" data-confirm="حذف شود؟">@csrf @method('delete')
          @if($a->status === 'pending')<label class="check"><input type="checkbox" name="panel_only" value="1"> فقط از پنل</label>@endif
          <button class="btn btn-danger" type="submit">حذف</button>
        </form>
      </div>
    @endcan
  @endif
</x-layouts.app>
