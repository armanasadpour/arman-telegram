<x-layouts.app title="گزارش رویدادها">
  <div class="page-head">
    <div>
      <h1>گزارش رویدادها</h1>
      <p class="muted">رکوردها قابل ویرایش یا حذف نیستند و با زنجیره هش SHA-256 محافظت می‌شوند.</p>
    </div>
    <form method="post" action="{{ route('audit.verify') }}">
      @csrf
      <button class="btn btn-secondary" type="submit">بررسی یکپارچگی</button>
    </form>
  </div>
  <div class="card">
    <form method="get" class="mb-8 form-grid">
      <div class="field">
        <label for="action">نوع رویداد</label>
        <select id="action" name="action" class="input">
          <option value="">همه</option>
          @foreach($actions as $a)
            <option value="{{ $a }}" @selected(($filters['action'] ?? '') === $a)>{{ $labels[$a] ?? $a }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="user_id">کاربر</label>
        <select id="user_id" name="user_id" class="input">
          <option value="">همه</option>
          @foreach($users as $u)
            <option value="{{ $u->id }}" @selected((int) ($filters['user_id'] ?? 0) === $u->id)>{{ $u->name }} ({{ $u->username }})</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="days">بازه زمانی</label>
        <select id="days" name="days" class="input">
          <option value="">همه</option>
          @foreach([1 => '۲۴ ساعت اخیر', 7 => '۷ روز اخیر', 30 => '۳۰ روز اخیر', 90 => '۹۰ روز اخیر', 365 => 'یک سال اخیر'] as $d => $label)
            <option value="{{ $d }}" @selected((int) ($filters['days'] ?? 0) === $d)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="field">
        <label for="q">جستجو (توضیح، نام کاربری، IP)</label>
        <input id="q" name="q" class="input" value="{{ $filters['q'] ?? '' }}" maxlength="100">
      </div>
      <div class="actions">
        <button class="btn" type="submit">اعمال فیلتر</button>
        @if(array_filter($filters))<a class="btn btn-secondary" href="{{ route('audit.index') }}">حذف فیلترها</a>@endif
      </div>
    </form>
  </div>
  <div class="card">
    @if($logs->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>رویدادی با این فیلترها یافت نشد.</div></div>
    @else
      <div class="table-wrap">
        <table class="table stack">
          <thead><tr><th>#</th><th>زمان</th><th>کاربر</th><th>رویداد</th><th>توضیح</th><th>IP</th></tr></thead>
          <tbody>
          @foreach($logs as $log)
            <tr>
              <td data-label="شماره"><a href="{{ route('audit.show', $log) }}">{{ fa((string) $log->id) }}</a></td>
              <td data-label="زمان">{{ jdate($log->created_at, 'Y/m/d H:i:s') }}</td>
              <td data-label="کاربر"><bdi>{{ $log->actor_label }}</bdi></td>
              <td data-label="رویداد"><span @class(['badge', 'badge-danger' => str_contains($log->action, 'failed') || str_contains($log->action, 'delete') || str_contains($log->action, 'blocked'), 'badge-warning' => str_contains($log->action, 'deactivate') || str_contains($log->action, 'password_reset')])>{{ $labels[$log->action] ?? $log->action }}</span></td>
              <td data-label="توضیح">{{ $log->description }} @if($log->reason)<div class="small muted">دلیل: {{ $log->reason }}</div>@endif</td>
              <td data-label="IP"><bdi class="ltr">{{ $log->ip }}</bdi></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      {{ $logs->links() }}
    @endif
  </div>
</x-layouts.app>
