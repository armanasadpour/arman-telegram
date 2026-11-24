<x-layouts.app title="ساخت‌های نیازمند رسیدگی">
  <div class="page-head">
    <div>
      <h1>ساخت اکانت‌ها</h1>
      <p class="muted">ساخت اکانت در سرور دو مرحله‌ای است؛ اگر مرحله‌ای ناتمام بماند اینجا نمایش داده می‌شود. موارد نیمه‌کاره هر دقیقه خودکار دوباره تلاش می‌شوند.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('accounts.index') }}">بازگشت</a>
  </div>
  <div class="card">
    <h2>باز</h2>
    @if($open->isEmpty())
      <div class="empty"><div>مورد بازی وجود ندارد.</div></div>
    @else
      @foreach($open as $j)
        <div class="card">
          <dl class="kv">
            <dt>نام کاربری</dt><dd><bdi class="ltr">{{ $j->username }}</bdi> <span class="badge badge-warning">{{ $j->statusLabel() }}</span></dd>
            <dt>گروه</dt><dd>{{ $j->spec['group'] ?? '—' }}</dd>
            <dt>شناسه سرور</dt><dd>{{ $j->ibs_user_id ?? 'نامشخص' }}</dd>
            <dt>تلاش‌ها</dt><dd>{{ fa($j->attempts) }}</dd>
            <dt>زمان</dt><dd>{{ jdate($j->created_at) }} — {{ $j->creator?->name ?? '—' }}</dd>
            <dt>کلید پیگیری</dt><dd class="ltr tiny">{{ $j->job_key }}</dd>
            <dt>خطا</dt><dd>{{ $j->last_error ?? '—' }}</dd>
          </dl>
          <div class="actions">
            @can('accounts.create')
              @if($j->ibs_user_id)
                <form method="post" action="{{ route('accounts.jobs.retry', $j) }}">@csrf<button class="btn" type="submit">تلاش دوباره</button></form>
              @endif
            @endcan
            @can('accounts.sensitive')
              <form method="post" action="{{ route('accounts.jobs.resolve', $j) }}" class="actions" data-confirm="این مورد بسته شود؟">
                @csrf
                <input name="reason" class="input w-280" placeholder="توضیح رسیدگی (مثلاً: کاربر خام دستی حذف شد)" required minlength="3" aria-label="توضیح">
                <button class="btn btn-secondary" type="submit">بستن دستی</button>
              </form>
            @endcan
          </div>
        </div>
      @endforeach
    @endif
  </div>
  <div class="card">
    <h2>اخیر</h2>
    @if($recent->isEmpty())
      <div class="empty"><div>هنوز اکانتی از پنل ساخته نشده است.</div></div>
    @else
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>زمان</th><th>نام کاربری</th><th>وضعیت</th><th>توسط</th><th>توضیح</th></tr></thead>
        <tbody>
        @foreach($recent as $j)
          <tr>
            <td data-label="زمان">{{ jdate($j->created_at) }}</td>
            <td data-label="نام کاربری">@if($j->account)<a href="{{ route('accounts.show', $j->account) }}"><bdi class="ltr">{{ $j->username }}</bdi></a>@else<bdi class="ltr">{{ $j->username }}</bdi>@endif</td>
            <td data-label="وضعیت"><span @class(['badge', 'badge-success' => $j->status === 'completed', 'badge-danger' => $j->status !== 'completed'])>{{ $j->statusLabel() }}</span></td>
            <td data-label="توسط">{{ $j->creator?->name ?? '—' }}</td>
            <td data-label="توضیح">{{ $j->last_error ?? '—' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    @endif
  </div>
</x-layouts.app>
