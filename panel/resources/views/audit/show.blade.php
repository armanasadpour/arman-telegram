<x-layouts.app title="جزئیات رویداد">
  <div class="page-head">
    <h1>رویداد #{{ fa((string) $log->id) }}</h1>
    <a class="btn btn-secondary" href="{{ route('audit.index') }}">بازگشت</a>
  </div>
  <div class="card">
    <dl class="kv">
      <dt>زمان</dt><dd>{{ jdate($log->created_at, 'Y/m/d H:i:s') }} <span class="muted">({{ fa_ago($log->created_at) }})</span></dd>
      <dt>کاربر</dt><dd><bdi>{{ $log->actor_label }}</bdi></dd>
      <dt>رویداد</dt><dd>{{ $labels[$log->action] ?? $log->action }} <bdi class="muted ltr">{{ $log->action }}</bdi></dd>
      <dt>موضوع</dt><dd>{{ $log->subject_type ? $log->subject_type.' #'.$log->subject_id : '—' }}</dd>
      <dt>توضیح</dt><dd>{{ $log->description ?? '—' }}</dd>
      <dt>دلیل</dt><dd>{{ $log->reason ?? '—' }}</dd>
      <dt>IP</dt><dd><bdi class="ltr">{{ $log->ip ?? '—' }}</bdi></dd>
      <dt>مرورگر</dt><dd class="small ltr">{{ $log->user_agent ?: '—' }}</dd>
      <dt>هش</dt><dd class="tiny ltr">{{ $log->hash }}</dd>
    </dl>
  </div>
  @if($log->changes)
    <div class="card">
      <h2>تغییرات</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>فیلد</th><th>قبل</th><th>بعد</th></tr></thead>
          <tbody>
          @foreach($log->changes as $field => $pair)
            <tr>
              <td><bdi class="ltr">{{ $field }}</bdi></td>
              <td><pre class="code">{{ fa_value($pair[0] ?? null) }}</pre></td>
              <td><pre class="code">{{ fa_value($pair[1] ?? null) }}</pre></td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    </div>
  @endif
</x-layouts.app>
