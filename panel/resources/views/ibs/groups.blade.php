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
          <thead><tr><th>گروه</th><th>اعتبار (از اولین اتصال)</th><th>اتصال همزمان</th><th>تعرفه</th><th>نوع حجم</th><th>اکانت‌ها در پنل</th></tr></thead>
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
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>
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
