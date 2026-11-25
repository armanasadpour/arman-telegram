<x-layouts.app title="گروه‌های L2TP / WireGuard">
  <div class="page-head">
    <div>
      <h1>گروه‌های L2TP / WireGuard</h1>
      <p class="muted">هر گروه مشخص می‌کند اکانت روی کدام روتر، با کدام پروفایل PPP یا اینترفیس WireGuard ساخته شود. حجم و روز را بسته‌ها یا فرم ساخت اکانت تعیین می‌کنند.</p>
    </div>
  </div>

  @if($routers->isEmpty())
    <div class="card"><div class="empty"><x-icon name="link"/><div>ابتدا یک روتر اضافه و متصل کنید.</div>@can('routers.sensitive')<a class="btn mt-8" href="{{ route('routers.create') }}">افزودن روتر</a>@endcan</div></div>
  @endif

  @foreach($routers as $r)
    @php($rg = $groups->where('router_id', $r->id))
    <section class="card mb-16">
      <header class="step-head">
        <span @class(['dot', 'dot-on' => $r->isUp(), 'dot-off' => ! $r->isUp()])></span>
        <div><h2>{{ $r->name }}</h2><p class="muted small ltr"><bdi>{{ $r->host }}</bdi></p></div>
      </header>
      <div class="actions mb-8">
        @can('routers.update')
          @if($r->l2tp_enabled)<a class="btn btn-secondary btn-sm" href="{{ route('mt.groups.create', ['router' => $r->id, 'protocol' => 'l2tp']) }}">+ گروه L2TP</a>@endif
          @if($r->wireguard_enabled)<a class="btn btn-secondary btn-sm" href="{{ route('mt.groups.create', ['router' => $r->id, 'protocol' => 'wireguard']) }}">+ گروه WireGuard</a>@endif
          @if(! $r->l2tp_enabled && ! $r->wireguard_enabled)<span class="muted small">L2TP یا WireGuard این روتر هنوز فعال نشده است. <a href="{{ route('routers.show', $r) }}">تنظیمات روتر</a></span>@endif
        @endcan
      </div>
      @if($rg->isEmpty())
        <div class="muted small">هنوز گروهی برای این روتر ساخته نشده است.</div>
      @else
        <div class="table-wrap"><table class="table">
          <thead><tr><th>نام</th><th>پروتکل</th><th>تنظیم روی روتر</th><th>چندکاربره</th><th>اکانت</th><th>وضعیت</th><th></th></tr></thead>
          <tbody>
          @foreach($rg as $g)
            <tr>
              <td><strong>{{ $g->name }}</strong></td>
              <td><span class="badge {{ $g->isWireguard() ? 'badge-primary' : '' }}">{{ $g->protocolLabel() }}</span></td>
              <td class="ltr small">@if($g->isWireguard()){{ $g->wg_interface }} · {{ $g->wg_network }} · :{{ $g->wg_listen_port }}@if($g->rate_limit) · {{ $g->rate_limit }}@endif @else profile: {{ $g->ppp_profile }} @endif</td>
              <td>{{ $g->isWireguard() ? '۱ (ذاتی)' : ($g->multi_login ? fa($g->multi_login) : 'نامحدود') }}</td>
              <td><a href="{{ route('mt.accounts.index', ['group' => $g->id]) }}">{{ fa($g->accounts_count) }}</a></td>
              <td>@if($g->is_active)<span class="badge badge-success">فعال</span>@else<span class="badge">غیرفعال</span>@endif</td>
              <td class="actions">
                @can('routers.update')
                  <a class="btn btn-secondary btn-sm" href="{{ route('mt.groups.edit', $g) }}">ویرایش</a>
                  @if($g->accounts_count === 0)
                    <form method="post" action="{{ route('mt.groups.destroy', $g) }}" data-confirm="گروه «{{ $g->name }}» حذف شود؟">@csrf @method('delete')<button class="btn btn-danger btn-sm" type="submit">حذف</button></form>
                  @endif
                @endcan
              </td>
            </tr>
          @endforeach
          </tbody>
        </table></div>
      @endif
    </section>
  @endforeach
</x-layouts.app>
