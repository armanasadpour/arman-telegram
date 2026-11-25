<x-layouts.app title="اکانت {{ $a->username }}">
  <div class="page-head">
    <div><h1><bdi class="ltr">{{ $a->username }}</bdi></h1><p class="muted">آخرین به‌روزرسانی: {{ $a->last_synced_at ? fa_ago($a->last_synced_at) : 'هرگز' }}</p></div>
    <div class="actions">
      <a class="btn" href="{{ route('portal.sell', ['account' => $a->id]) }}">تمدید / افزایش حجم</a>
      <a class="btn btn-secondary" href="{{ route('portal.accounts') }}">بازگشت</a>
    </div>
  </div>
  <div class="grid stats mb-16">
    <div class="card stat"><div class="label">وضعیت</div><div class="value small">@include('accounts._status', ['a' => $a])</div></div>
    <div class="card stat"><div class="label">انقضا</div><div class="value small">{{ $a->expires_at ? jdate($a->expires_at, 'Y/m/d') : fa_duration($a->effectiveRelExpSeconds()) }}</div><div class="hint">{{ $a->expires_at ? ($a->expires_at->isPast() ? 'منقضی شده' : fa((int) ceil(now()->diffInSeconds($a->expires_at) / 86400)).' روز باقی‌مانده') : 'پس از اولین اتصال شروع می‌شود' }}</div></div>
    <div class="card stat"><div class="label">حجم</div><div class="value small">{{ $a->isVolumeLimited() ? fa_bytes($a->remainingBytes()) : 'بدون محدودیت' }}</div></div>
    <div class="card stat"><div class="label">اتصال</div><div class="value small">{{ $a->is_online ? fa($a->online_sessions).' نشست آنلاین' : 'آفلاین' }}</div></div>
  </div>
  <div class="card">
    <h2>سوابق فروش این اکانت</h2>
    @if($sales->isEmpty())<div class="empty"><div>—</div></div>@else
    <div class="table-wrap"><table class="table stack"><tbody>
      @foreach($sales as $s)<tr><td data-label="زمان">{{ jdate($s->created_at) }}</td><td data-label="بسته">{{ $s->package?->name }}</td><td data-label="سود">{{ fa_money($s->profit_rial) }}</td><td data-label="وضعیت">@php([$l, $t] = \App\Models\ResellerSale::STATUS[$s->status])<span class="badge badge-{{ $t }}">{{ $l }}</span></td></tr>@endforeach
    </tbody></table></div>
    @endif
  </div>
</x-layouts.app>
