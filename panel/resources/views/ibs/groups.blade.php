<x-layouts.app title="گروه‌ها و تعرفه‌ها">
  <div class="page-head">
    <div>
      <h1>گروه‌ها و تعرفه‌های سرور</h1>
      <p class="muted">آخرین همگام‌سازی: {{ $syncedAt ? jdate(\Carbon\Carbon::parse($syncedAt)).' ('.fa_ago(\Carbon\Carbon::parse($syncedAt)).')' : 'هنوز انجام نشده' }} — خودکار هر ساعت</p>
    </div>
    @can('ibs_groups.update')
      <form method="post" action="{{ route('ibs.groups.sync') }}">@csrf<button class="btn" type="submit">همگام‌سازی از سرور</button></form>
    @endcan
  </div>
  <div class="card">
    <h2>گروه‌ها</h2>
    @if($groups->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>هنوز گروهی همگام‌سازی نشده است.</div></div>
    @else
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>گروه</th><th>اعتبار (از اولین اتصال)</th><th>اتصال همزمان</th><th>تعرفه</th><th>نوع حجم</th><th>اکانت‌ها در پنل</th>@can('ibs_groups.update')<th></th>@endcan</tr></thead>
          <tbody>
          @foreach($groups as $g)
            <tr>
              <td data-label="گروه"><bdi>{{ $g->name }}</bdi> @unless($g->present)<span class="badge badge-danger">حذف‌شده در سرور</span>@endunless</td>
              <td data-label="اعتبار">{{ fa_duration($g->rel_exp_seconds) }}</td>
              <td data-label="اتصال همزمان">{{ $g->multi_login !== null ? fa($g->multi_login) : '—' }}</td>
              <td data-label="تعرفه"><bdi>{{ $g->charge_name ?? '—' }}</bdi></td>
              <td data-label="نوع حجم">
                @if($g->charge?->isVolume())<span class="badge badge-primary">حجمی</span>@else<span class="badge">بدون محدودیت حجم</span>@endif
              </td>
              <td data-label="اکانت‌ها"><a href="{{ route('accounts.index', ['group' => $g->name]) }}">{{ fa((int) ($accountCounts[$g->name] ?? 0)) }}</a></td>
              @can('ibs_groups.update')
                <td data-label="">@if($g->present)<a class="btn btn-secondary btn-sm" href="{{ route('ibs.groups.edit', $g->name) }}">ویرایش</a>@endif</td>
              @endcan
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
  @can('ibs_groups.update')
  <div class="card">
    <h2>تعریف گروه جدید در سرور</h2>
    @if($activeCharges->isEmpty())
      <p class="muted">برای ساخت گروه ابتدا باید حداقل یک تعرفه در سرور وجود داشته باشد و همگام‌سازی شود.</p>
    @else
      <form method="post" action="{{ route('ibs.groups.store') }}">
        @csrf
        <div class="form-grid">
        <div class="field">
          <label for="name">نام گروه</label>
          <input class="input ltr" id="name" name="name" value="{{ old('name') }}" required maxlength="60" pattern="[A-Za-z0-9_\-]+" placeholder="مثلاً 1-Month-20GB">
          <div class="help">فقط حروف انگلیسی، عدد، «_» و «-». بعداً قابل تغییر نیست.</div>
        </div>
        @include('ibs.partials.group-fields', ['g' => null])
        <div class="field">
          <label for="comment">توضیح (اختیاری)</label>
          <input class="input" id="comment" name="comment" value="{{ old('comment') }}" maxlength="200">
        </div>
        </div>
        <button class="btn" type="submit">ساخت گروه در سرور</button>
      </form>
    @endif
  </div>
  @endcan
  <div class="card">
    <h2>تعرفه‌ها</h2>
    <p class="muted">تعرفه‌ای «حجمی» است که اعتبار را به‌ازای هر کیلوبایت کم کند (cpk بزرگ‌تر از صفر). حجم باقی‌مانده اکانت‌ها فقط برای این تعرفه‌ها محاسبه می‌شود.</p>
    @if($charges->isEmpty())
      <div class="empty"><div>هنوز تعرفه‌ای همگام‌سازی نشده است.</div></div>
    @else
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>تعرفه</th><th>اعتبار به‌ازای هر کیلوبایت</th><th>هر واحد اعتبار</th><th>نوع</th></tr></thead>
          <tbody>
          @foreach($charges as $c)
            <tr>
              <td data-label="تعرفه"><bdi>{{ $c->name }}</bdi> @unless($c->present)<span class="badge badge-danger">حذف‌شده</span>@endunless</td>
              <td data-label="cpk"><bdi class="ltr">{{ rtrim(rtrim(number_format((float) $c->cpk, 10, '.', ''), '0'), '.') }}</bdi></td>
              <td data-label="هر واحد">{{ $c->isVolume() ? fa_bytes((int) round($c->bytesPerCredit())) : '—' }}</td>
              <td data-label="نوع">@if($c->isVolume())<span class="badge badge-primary">حجمی</span>@else<span class="badge">بدون محدودیت حجم</span>@endif</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
</x-layouts.app>
