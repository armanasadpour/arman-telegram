<x-layouts.app title="اکانت‌ها">
  <div class="page-head">
    <div>
      <h1>اکانت‌ها</h1>
      <p class="muted">
        آنلاین‌ها: {{ $syncedAt['onlines'] ? fa_ago(\Carbon\Carbon::parse($syncedAt['onlines'])) : 'هنوز همگام نشده' }}
        — اکانت‌ها: {{ $syncedAt['accounts'] ? fa_ago(\Carbon\Carbon::parse($syncedAt['accounts'])) : 'هنوز همگام نشده' }}
      </p>
    </div>
    <div class="actions">
      @can('accounts.delete')
        @if($configured)<a class="btn btn-secondary" href="{{ route('accounts.cleanup') }}">حذف منقضی‌های قدیمی</a>@endif
      @endcan
      @can('accounts.update')
        @if($configured && $counts['online'] > 0)<form method="post" action="{{ route('accounts.kick-all') }}" data-confirm="همه {{ fa($counts['online']) }} کاربر آنلاین OpenVPN قطع شوند؟">@csrf<button class="btn btn-danger" type="submit">⛔ قطع همه نشست‌ها</button></form>@endif
      @endcan
      @can('accounts.create')
        @if($configured)<a class="btn" href="{{ route('accounts.create') }}">ساخت اکانت</a>@endif
      @endcan
    </div>
  </div>

  @unless($configured)
    <div class="alert alert-warning">اتصال به سرور تنظیم نشده است. @can('settings.view')<a href="{{ route('ibs.settings') }}">تنظیم اتصال به سرور</a>@endcan</div>
  @endunless
  @if($openJobs)
    <div class="alert alert-warning">{{ fa($openJobs) }} ساخت اکانت نیمه‌کاره یا نیازمند رسیدگی وجود دارد. <a href="{{ route('accounts.jobs') }}">مشاهده</a></div>
  @endif
  @if($import && ($import['running'] ?? false))
    @php($left = $import['total'] !== null ? max(0, (int) $import['total'] - (int) $import['cursor']) : null)
    <div class="alert alert-warning">
      <div>وارد کردن اکانت‌ها از سرور در حال انجام است: {{ fa((int) $import['cursor']) }} از {{ $import['total'] !== null ? fa((int) $import['total']) : '…' }} شناسه بررسی شد.</div>
      <div class="muted small mt-4">
        {{ fa((int) $import['imported']) }} اکانت جدید وارد شد
        · {{ fa((int) ($import['existing'] ?? 0)) }} از قبل در پنل بود
        · {{ fa((int) ($import['no_username'] ?? 0)) }} کاربر بدون نام کاربری (رد شد)
        @if(($import['missing'] ?? 0) > 0) · {{ fa((int) $import['missing']) }} در سرور یافت نشد @endif
        @if(($import['errors'] ?? 0) > 0) · {{ fa((int) $import['errors']) }} اکانت به دلیل داده نامعتبر رد شد (در لاگ) @endif
        @if($importRate && $left !== null) · سرعت {{ fa((int) $importRate) }} در دقیقه، حدود {{ fa_duration((int) ($left / max(1, $importRate) * 60)) }} مانده @endif
      </div>
      <div class="mt-8">با Cron هر دقیقه ادامه پیدا می‌کند.
        @can('accounts.create')
          <form method="post" action="{{ route('accounts.import-continue') }}" class="inline">@csrf<button class="btn btn-secondary btn-sm" type="submit">ادامه همین الان</button></form>
        @endcan
      </div>
    </div>
  @endif

  <div class="grid stats mb-16">
    <a class="card stat" href="{{ route('accounts.index') }}"><div class="label">اکانت‌ها در پنل</div><div class="value">{{ fa($counts['total']) }}</div></a>
    <a class="card stat" href="{{ route('accounts.index', ['status' => 'online']) }}"><div class="label">آنلاین</div><div class="value">{{ fa($counts['online']) }}</div></a>
    <a class="card stat" href="{{ route('accounts.index', ['status' => 'expired']) }}"><div class="label">منقضی</div><div class="value">{{ fa($counts['expired']) }}</div></a>
    <a class="card stat" href="{{ route('accounts.index', ['status' => 'locked']) }}"><div class="label">غیرفعال (قفل)</div><div class="value">{{ fa($counts['locked']) }}</div></a>
  </div>

  <div class="card">
    <form method="get" class="form-grid mb-8">
      <div class="field"><label for="q">جستجو</label><input id="q" name="q" class="input" value="{{ $filters['q'] ?? '' }}" placeholder="نام کاربری یا نام کاربر"></div>
      <div class="field"><label for="status">وضعیت</label>
        <select id="status" name="status" class="input">
          <option value="">همه</option>
          @foreach(['online' => 'آنلاین', 'active' => 'فعال', 'not_started' => 'شروع‌نشده', 'expiring' => 'انقضا تا ۳ روز آینده', 'expired' => 'منقضی', 'locked' => 'غیرفعال (قفل)', 'deleted' => 'حذف‌شده در سرور'] as $k => $v)
            <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>
          @endforeach
        </select></div>
      <div class="field"><label for="group">گروه</label>
        <select id="group" name="group" class="input"><option value="">همه</option>
          @foreach($groups as $g)<option value="{{ $g }}" @selected(($filters['group'] ?? '') === $g)>{{ $g }}</option>@endforeach
        </select></div>
      <div class="field"><label for="creator">ساخته‌شده توسط</label>
        <select id="creator" name="creator" class="input"><option value="">همه مدیران</option>
          @foreach($creators as $c)<option value="{{ $c->id }}" @selected((string) ($filters['creator'] ?? '') === (string) $c->id)>{{ $c->name }} ({{ $c->username }})</option>@endforeach
          <option value="imported" @selected(($filters['creator'] ?? '') === 'imported')>وارد‌شده از سرور</option>
        </select></div>
      <div class="actions"><button class="btn" type="submit">اعمال</button>@if(array_filter($filters))<a class="btn btn-secondary" href="{{ route('accounts.index') }}">حذف فیلتر</a>@endif</div>
    </form>

    @if($accounts->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>{{ array_filter($filters) ? 'نتیجه‌ای در پنل یافت نشد.' : 'هنوز اکانتی در پنل نیست. اکانت بسازید یا از سرور وارد کنید.' }}</div>
        @if(($filters['q'] ?? '') !== '' && $configured && preg_match('/^[A-Za-z0-9._@-]{2,64}$/', $filters['q']))
          @can('accounts.create')
            <p class="muted small mt-8">شاید این اکانت در سرور هست ولی هنوز به پنل وارد نشده:</p>
            <form method="post" action="{{ route('accounts.import') }}">@csrf<input type="hidden" name="username" value="{{ $filters['q'] }}"><button class="btn btn-secondary" type="submit">جستجوی «{{ $filters['q'] }}» در سرور و وارد کردن</button></form>
          @endcan
        @endif
      </div>
    @else
      <div class="table-wrap">
        <div class="acc-list only-mobile">
          @foreach($accounts as $a)@include('accounts._card', ['a' => $a, 'url' => route('accounts.show', $a)])@endforeach
        </div>
        <table class="table only-desktop">
          <thead><tr><th>نام کاربری</th><th>گروه</th><th>سازنده</th><th>وضعیت</th><th>انقضا</th><th>حجم باقی‌مانده</th><th>به‌روزرسانی</th></tr></thead>
          <tbody>
          @foreach($accounts as $a)
            <tr>
              <td data-label="نام کاربری"><a href="{{ route('accounts.show', $a) }}"><bdi class="ltr">{{ $a->username }}</bdi></a>@if($a->displayComment())<div class="small">👤 {{ $a->displayComment() }}</div>@endif</td>
              <td data-label="گروه"><bdi>{{ $a->group_name }}</bdi></td>
              <td data-label="سازنده" class="small">{{ $a->creatorLabel() }}</td>
              <td data-label="وضعیت">@include('accounts._status', ['a' => $a])</td>
              <td data-label="انقضا">{{ $a->expires_at ? jdate($a->expires_at, 'Y/m/d').' ('.fa_ago($a->expires_at).')' : ($a->first_login_ibs ? '—' : 'پس از اولین اتصال') }}@include('accounts._mini', ['m' => $a->timeMeter()])</td>
              <td data-label="حجم">{{ $a->isVolumeLimited() ? fa_bytes($a->remainingBytes()) : 'بدون محدودیت' }}@include('accounts._mini', ['m' => $a->volumeMeter()])</td>
              <td data-label="به‌روزرسانی">
                @if($a->isStale())<span class="badge badge-warning">{{ $a->last_synced_at ? fa_ago($a->last_synced_at) : 'هرگز' }}</span>
                @else<span class="muted small">{{ fa_ago($a->last_synced_at) }}</span>@endif
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      {{ $accounts->links() }}
    @endif
  </div>

  @can('accounts.create')
    @if($configured)
      <div class="grid two">
        <div class="card">
          <h2>وارد کردن یک اکانت از سرور</h2>
          <form method="post" action="{{ route('accounts.import') }}" class="actions">
            @csrf
            <input name="username" class="input ltr w-280" placeholder="نام کاربری در سرور" required aria-label="نام کاربری">
            <button class="btn btn-secondary" type="submit">وارد کردن</button>
          </form>
        </div>
        <div class="card">
          <h2>وارد کردن همه اکانت‌های سرور</h2>
          <p class="muted">اکانت‌های موجود در سرور به پنل اضافه می‌شوند (چیزی در سرور تغییر نمی‌کند). کار در پس‌زمینه با Cron و هر دقیقه حدود چند صد اکانت انجام می‌شود.</p>
          <form method="post" action="{{ route('accounts.import-all') }}" data-confirm="وارد کردن همه اکانت‌های سرور شروع شود؟">
            @csrf
            <button class="btn btn-secondary" type="submit" @disabled($import['running'] ?? false)>شروع وارد کردن</button>
          </form>
        </div>
      </div>
    @endif
  @endcan
</x-layouts.app>
