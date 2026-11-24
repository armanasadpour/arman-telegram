<x-layouts.app title="اکانت {{ $a->username }}">
  <div class="page-head">
    <div>
      <h1><bdi class="ltr">{{ $a->username }}</bdi></h1>
      <p class="muted">
        آخرین به‌روزرسانی از IBSng: {{ $a->last_synced_at ? jdate($a->last_synced_at, 'Y/m/d H:i:s').' ('.fa_ago($a->last_synced_at).')' : 'هرگز' }}
      </p>
    </div>
    <div class="actions">
      <form method="post" action="{{ route('accounts.refresh', $a) }}">@csrf<button class="btn btn-secondary" type="submit">به‌روزرسانی از IBSng</button></form>
      <a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a>
    </div>
  </div>

  @if(session('new_password'))
    <div class="alert alert-success">رمز اکانت: <strong><bdi class="ltr">{{ session('new_password') }}</bdi></strong> — این رمز فقط همین یک بار نمایش داده می‌شود؛ آن را به مشتری تحویل دهید.</div>
  @endif
  @if(session('revealed_password'))
    <div class="alert alert-warning">رمز فعلی (خوانده‌شده از IBSng): <strong><bdi class="ltr">{{ session('revealed_password') }}</bdi></strong> — مشاهده رمز در گزارش رویدادها ثبت شد.</div>
  @endif
  @if($a->isStale())
    <div class="alert alert-warning">این اطلاعات قدیمی است؛ برای دیدن وضعیت لحظه‌ای «به‌روزرسانی از IBSng» را بزنید.</div>
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
    <div class="card stat">
      <div class="label">انقضا</div>
      @if($a->expires_at)
        <div class="value small">{{ jdate($a->expires_at, 'Y/m/d H:i') }}</div>
        <div class="hint">{{ $a->expires_at->isPast() ? 'منقضی شده — '.fa_ago($a->expires_at) : fa((int) ceil(now()->diffInSeconds($a->expires_at) / 86400)).' روز باقی‌مانده' }}</div>
      @else
        <div class="value small">{{ $rel ? fa_duration($rel) : '—' }}</div>
        <div class="hint">پس از اولین اتصال شروع می‌شود</div>
      @endif
    </div>
    <div class="card stat">
      <div class="label">حجم</div>
      @if($a->isVolumeLimited())
        <div class="value small">{{ fa_bytes($rem) }}</div>
        <div class="hint">باقی‌مانده @if($total) از {{ fa_bytes($total) }} — مصرف‌شده {{ fa_bytes($used) }} ({{ fa($pct) }}٪) @else (حجم کل این اکانت در پنل ثبت نشده) @endif</div>
      @else
        <div class="value small">بدون محدودیت</div>
        <div class="hint">تعرفه گروه حجمی نیست</div>
      @endif
    </div>
    <div class="card stat">
      <div class="label">اتصال</div>
      <div class="value small">{{ $a->is_online ? fa($a->online_sessions).' نشست آنلاین' : 'آفلاین' }}</div>
      <div class="hint">حداکثر همزمان: {{ $a->effectiveMultiLogin() !== null ? fa($a->effectiveMultiLogin()) : '—' }}</div>
    </div>
  </div>

  <div class="grid two">
    <div class="card">
      <h2>اطلاعات</h2>
      <dl class="kv">
        <dt>شناسه IBSng</dt><dd><bdi class="ltr">{{ $a->ibs_user_id }}</bdi></dd>
        <dt>گروه</dt><dd><bdi>{{ $a->group_name }}</bdi> @if($a->group?->charge_name)<span class="muted">(تعرفه {{ $a->group->charge_name }})</span>@endif</dd>
        <dt>مدت اعتبار</dt><dd>{{ fa_duration($rel) }} @if($a->rel_exp_seconds)<span class="badge">مختص این اکانت</span>@endif</dd>
        <dt>اولین اتصال</dt><dd>{{ $a->first_login_ibs ? fa($a->first_login_ibs).' (به ساعت سرور IBSng)' : 'هنوز وصل نشده' }}</dd>
        <dt>ساخت در IBSng</dt><dd>{{ $a->ibs_created_at ? fa($a->ibs_created_at) : '—' }}</dd>
        <dt>منبع</dt><dd>{{ $a->source === 'panel' ? 'ساخته‌شده در پنل'.($a->creator ? ' توسط '.$a->creator->name : '') : 'وارد‌شده از IBSng' }}</dd>
        <dt>توضیح</dt><dd>{{ $a->displayComment() ?? '—' }}</dd>
        @if($a->locked)<dt>دلیل قفل</dt><dd>{{ $a->lock_reason }}</dd>@endif
      </dl>
    </div>
    <div class="card">
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

  @unless($a->ibs_deleted_at)
    @can('accounts.update')
      <div class="grid two">
        @if($applicable->isNotEmpty())
          <div class="card">
            <h2>اعمال بسته</h2>
            <form method="post" action="{{ route('accounts.apply-package', $a) }}" data-confirm="بسته روی این اکانت اعمال شود؟">
              @csrf
              <div class="field"><label for="apply_pkg">بسته تمدید / افزایش حجم</label>
                <select id="apply_pkg" name="package_id" class="input" required>
                  @foreach($applicable as $row)<option value="{{ $row['p']->id }}">{{ $row['p']->name }} — {{ $row['p']->summary() }} — {{ fa_money($row['q']->finalPrice) }}</option>@endforeach
                </select></div>
              <div class="field"><label for="apply_reason">دلیل / شماره سفارش</label><input id="apply_reason" name="reason" class="input" required minlength="3"></div>
              <button class="btn" type="submit">اعمال بسته</button>
            </form>
          </div>
        @endif
        <div class="card">
          <h2>تمدید دستی</h2>
          <p class="muted">سیاست فعلی: {{ $renewalMode === 'smart' ? 'اگر فعال است از انقضای فعلی، اگر منقضی شده از الان' : 'همیشه از تاریخ انقضای فعلی' }}.</p>
          <form method="post" action="{{ route('accounts.renew', $a) }}" data-confirm="اکانت تمدید شود؟">
            @csrf
            <div class="form-grid">
              <div class="field"><label for="days">تعداد روز</label><input id="days" name="days" type="number" min="1" max="3650" class="input ltr" value="30" required></div>
              <div class="field"><label for="renew_reason">دلیل / شماره سفارش</label><input id="renew_reason" name="reason" class="input" required minlength="3"></div>
            </div>
            <button class="btn" type="submit">تمدید</button>
          </form>
        </div>
        @if($a->isVolumeLimited())
          <div class="card">
            <h2>افزایش حجم دستی</h2>
            <form method="post" action="{{ route('accounts.volume', $a) }}" data-confirm="حجم اضافه شود؟">
              @csrf
              <div class="form-grid">
                <div class="field"><label for="gb">گیگابایت</label><input id="gb" name="gb" type="number" step="0.1" min="0.1" class="input ltr" value="5" required></div>
                <div class="field"><label for="vol_reason">دلیل / شماره سفارش</label><input id="vol_reason" name="reason" class="input" required minlength="3"></div>
              </div>
              <button class="btn" type="submit">افزودن حجم</button>
            </form>
          </div>
        @endif
        <div class="card">
          <h2>{{ $a->locked ? 'فعال‌سازی دوباره' : 'غیرفعال‌سازی (قفل)' }}</h2>
          <form method="post" action="{{ route('accounts.lock', $a) }}" data-confirm="{{ $a->locked ? 'قفل برداشته شود؟' : 'اکانت قفل شود؟ کاربر دیگر نمی‌تواند وصل شود.' }}">
            @csrf
            <div class="field"><label for="lock_reason">دلیل</label><input id="lock_reason" name="reason" class="input" required minlength="3" maxlength="200"></div>
            <button class="btn {{ $a->locked ? '' : 'btn-danger' }}" type="submit">{{ $a->locked ? 'برداشتن قفل' : 'قفل کن' }}</button>
          </form>
        </div>
        <div class="card">
          <h2>تغییر رمز</h2>
          <form method="post" action="{{ route('accounts.password', $a) }}" data-confirm="رمز اکانت تغییر کند؟">
            @csrf
            <div class="field"><label for="new_pw">رمز جدید (خالی = تولید خودکار)</label><input id="new_pw" name="password" class="input ltr" maxlength="32" autocomplete="off"></div>
            <button class="btn btn-secondary" type="submit">تغییر رمز</button>
          </form>
        </div>
      </div>
    @endcan
    <div class="grid two">
      @can('accounts.sensitive')
        <div class="card">
          <h2>مشاهده رمز فعلی</h2>
          <p class="muted">رمز از IBSng خوانده می‌شود (پنل آن را ذخیره نمی‌کند). عملیات حساس است و ثبت می‌شود.</p>
          <form method="post" action="{{ route('accounts.reveal', $a) }}">@csrf<button class="btn btn-secondary" type="submit">نمایش رمز</button></form>
        </div>
      @endcan
      @can('accounts.delete')
        <div class="card card-danger">
          <h2 class="text-danger">حذف از IBSng</h2>
          <p class="muted">اکانت از IBSng حذف می‌شود؛ سوابق اتصال در IBSng باقی می‌ماند. برگشت‌پذیر نیست.</p>
          <form method="post" action="{{ route('accounts.destroy', $a) }}" data-confirm="اکانت «{{ $a->username }}» برای همیشه از IBSng حذف شود؟">
            @csrf @method('delete')
            <div class="field"><label for="del_reason">دلیل</label><input id="del_reason" name="reason" class="input" required minlength="3"></div>
            <button class="btn btn-danger" type="submit">حذف اکانت</button>
          </form>
        </div>
      @endcan
    </div>
  @endunless
</x-layouts.app>
