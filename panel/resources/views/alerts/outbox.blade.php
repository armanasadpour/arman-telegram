<x-layouts.app title="صف پیام‌های ربات">
  <div class="page-head"><h1>صف پیام‌های ربات</h1><a class="btn btn-secondary" href="{{ route('bots.index') }}">ربات‌ها</a></div>
  <div class="card">
    <form method="get" class="form-grid mb-12">
      <div class="field"><label for="status">وضعیت</label><select class="input" id="status" name="status"><option value="">همه</option>
        @foreach(['pending' => 'در صف', 'sent' => 'ارسال‌شده', 'failed' => 'ناموفق'] as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="actions"><button class="btn" type="submit">اعمال</button></div>
    </form>
    @if($rows->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>پیامی نیست.</div></div>
    @else
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>زمان</th><th>ربات</th><th>متن</th><th>وضعیت</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
          <tr>
            <td data-label="زمان">{{ jdate($r->created_at) }}</td>
            <td data-label="ربات">{{ $r->bot?->label() }}</td>
            <td data-label="متن"><span class="small">{{ \Illuminate\Support\Str::limit($r->text, 140) }}</span></td>
            <td data-label="وضعیت">
              @if($r->status === 'sent')<span class="badge badge-success">ارسال شد</span>
              @elseif($r->status === 'failed')<span class="badge badge-danger">ناموفق</span> <span class="muted small">{{ $r->error }}</span>
              @else<span class="badge badge-primary">در صف</span>@if($r->attempts) <span class="muted small">تلاش {{ fa($r->attempts) }} — {{ $r->error }}</span>@endif @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
      {{ $rows->links() }}
    @endif
  </div>
</x-layouts.app>
