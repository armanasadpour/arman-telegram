{{-- Connection log: IPs this account connected from. $key = 'v:<id>' | 'm:<id>' --}}
@php($logs = app(\App\Services\ConnectionLog::class)->forAccount($key))
<div class="card" data-sheet="لاگ اتصال (IP)" data-sheet-icon="🌐">
  <h2>لاگ اتصال (IP)</h2>
  @if($logs->isEmpty())
    <div class="empty"><div>هنوز اتصالی ثبت نشده است. IPها از زمان نصب این نسخه، هنگام آنلاین بودن ثبت می‌شوند.</div></div>
  @else
    <div class="table-wrap"><table class="table">
      <thead><tr><th>IP</th>@if($logs->whereNotNull('device')->where('device', '>', 1)->isNotEmpty())<th>دستگاه</th>@endif<th>اولین بار</th><th>آخرین بار</th><th>دفعات</th></tr></thead>
      <tbody>
      @foreach($logs as $l)
        <tr>
          <td><bdi class="ltr">{{ $l->ip }}</bdi></td>
          @if($logs->whereNotNull('device')->where('device', '>', 1)->isNotEmpty())<td>{{ $l->device ? fa($l->device) : '—' }}</td>@endif
          <td class="small">{{ jdate(\Carbon\Carbon::parse($l->first_seen_at)) }}</td>
          <td class="small">{{ jdate(\Carbon\Carbon::parse($l->last_seen_at)) }}<div class="muted">{{ fa_ago(\Carbon\Carbon::parse($l->last_seen_at)) }}</div></td>
          <td>{{ fa($l->connections) }}</td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
  @endif
</div>
