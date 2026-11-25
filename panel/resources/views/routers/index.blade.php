<x-layouts.app title="روترهای میکروتیک">
  <div class="page-head">
    <div><h1>روترهای میکروتیک</h1><p class="muted">اتصال به RouterOS برای ساخت و حسابداری اکانت‌های L2TP و WireGuard.</p></div>
    @can('routers.sensitive')<a class="btn" href="{{ route('routers.create') }}">افزودن روتر</a>@endcan
  </div>
  @if($routers->isEmpty())
    <div class="card"><div class="empty"><x-icon name="link"/><div>هنوز روتری اضافه نشده است.</div></div></div>
  @else
    <div class="router-grid">
      @foreach($routers as $r)
        <a class="router-card {{ $r->isUp() ? 'is-up' : ($r->last_error_at ? 'is-down' : 'is-new') }}" href="{{ route('routers.show', $r) }}">
          <div class="router-top">
            <span class="router-dot" aria-hidden="true"></span>
            <div class="router-id"><strong>{{ $r->name }}</strong><bdi class="ltr muted small">{{ $r->host }}:{{ $r->port }}</bdi></div>
            @unless($r->is_active)<span class="badge">غیرفعال</span>@endunless
          </div>
          <div class="router-facts">
            <span>{{ $r->info['identity'] ?? '—' }}</span>
            <span>RouterOS {{ $r->info['version'] ?? '?' }}</span>
            <span>{{ $r->transportLabel() }}</span>
          </div>
          <div class="router-tags">
            <span @class(['badge', 'badge-success' => $r->l2tp_enabled])>L2TP {{ $r->l2tp_enabled ? 'فعال' : 'خاموش' }}</span>
            <span @class(['badge', 'badge-success' => $r->wireguard_enabled])>WireGuard {{ $r->wireguard_enabled ? 'فعال' : 'خاموش' }}</span>
          </div>
          <div class="router-foot muted small">
            @if($r->isUp())آخرین تماس موفق {{ fa_ago($r->last_ok_at) }}{{ $r->last_latency_ms ? ' — '.fa($r->last_latency_ms).' میلی‌ثانیه' : '' }}
            @elseif($r->last_error_at)<span class="text-danger">خطا {{ fa_ago($r->last_error_at) }}: {{ \Illuminate\Support\Str::limit($r->last_error, 80) }}</span>
            @else هنوز تست نشده @endif
          </div>
        </a>
      @endforeach
    </div>
  @endif
</x-layouts.app>
