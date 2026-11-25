@php($pl = ['l2tp' => 'L2TP', 'wireguard' => 'WireGuard'][$proto ?? ''] ?? 'L2TP / WireGuard')
<x-layouts.app :title="'گروه و تعرفه '.$pl">
  <div class="page-head">
    <div>
      <h1>گروه و تعرفه {{ $pl }}</h1>
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
          @if($r->l2tp_enabled && $proto !== 'wireguard')<a class="btn btn-secondary btn-sm" href="{{ route('mt.groups.create', ['router' => $r->id, 'protocol' => 'l2tp']) }}">+ گروه L2TP</a>@endif
          @if($r->wireguard_enabled && $proto !== 'l2tp')<a class="btn btn-secondary btn-sm" href="{{ route('mt.groups.create', ['router' => $r->id, 'protocol' => 'wireguard']) }}">+ گروه WireGuard</a>@endif
          @if(! ($proto === 'wireguard' ? $r->wireguard_enabled : ($proto === 'l2tp' ? $r->l2tp_enabled : ($r->l2tp_enabled || $r->wireguard_enabled))))<span class="muted small">{{ $pl }} روی این روتر هنوز فعال نشده است (صفحه روتر → تست اتصال → روشن کردن). <a href="{{ route('routers.show', $r) }}">تنظیمات روتر</a></span>@endif
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
              <td>{{ $g->isWireguard() ? fa($g->multi_login ?? 1).' دستگاه' : ($g->multi_login ? fa($g->multi_login) : 'نامحدود') }}</td>
              <td><a href="{{ route($g->isWireguard() ? 'wg.accounts' : 'l2tp.accounts', ['group' => $g->id]) }}">{{ fa($g->accounts_count) }}</a></td>
              <td>@if($g->is_active)<span class="badge badge-success">فعال</span>@else<span class="badge">غیرفعال</span>@endif</td>
              <td class="actions">
                @can('mt_accounts.create')
                  <a class="btn btn-secondary btn-sm" href="{{ route('mt.import', $g) }}">وارد کردن از روتر</a>
                @endcan
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
  <section class="card">
    <header class="step-head"><span class="step-no">₮</span><div><h2>تعرفه‌ها (بسته‌های فروش) {{ $pl }}</h2><p class="muted small">قیمت، مدت، حجم و تعداد کاربر/دستگاه هر بسته؛ در فروش پنل، همکاران و ربات استفاده می‌شود.</p></div></header>
    @can('packages.create')<div class="actions mb-8"><a class="btn btn-secondary btn-sm" href="{{ route('packages.create', ['protocol' => 'mikrotik']) }}">+ بسته جدید</a></div>@endcan
    @if($packages->isEmpty())
      <div class="muted small">هنوز بسته‌ای تعریف نشده است.</div>
    @else
      <div class="table-wrap"><table class="table">
        <thead><tr><th>بسته</th><th>نوع</th><th>گروه</th><th>مشخصات</th><th>قیمت</th><th></th></tr></thead>
        <tbody>
        @foreach($packages as $p)
          <tr>
            <td>{{ $p->name }} @unless($p->is_active)<span class="badge">غیرفعال</span>@endunless</td>
            <td>{{ $p->typeLabel() }}</td>
            <td>{{ $p->type === 'new' ? ($p->mtGroup?->name ?? '—') : 'همه اکانت‌های میکروتیک' }}</td>
            <td>{{ $p->summary() }}</td>
            <td>{{ fa_money($p->price_rial) }}</td>
            <td>@can('packages.update')<a class="btn btn-secondary btn-sm" href="{{ route('packages.edit', $p) }}">ویرایش</a>@endcan</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    @endif
  </section>
</x-layouts.app>
