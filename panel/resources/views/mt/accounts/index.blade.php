@php($pl = ['l2tp' => 'L2TP', 'wireguard' => 'WireGuard'][$proto ?? ''] ?? 'L2TP / WireGuard')
<x-layouts.app :title="'اکانت‌های '.$pl">
  @include('accounts._list-head', [
    'title' => 'اکانت‌های '.$pl,
    'subtitle' => 'مصرف و وضعیت هر دقیقه از روترها خوانده می‌شود.',
    'kpis' => [
      ['کل اکانت‌ها', $counts->total, url()->current(), 'indigo', 'layers'],
      ['آنلاین', $counts->online, url()->current().'?'.http_build_query(['status' => 'online']), 'green', 'wifi'],
      ['منقضی', $counts->expired, url()->current().'?'.http_build_query(['status' => 'expired']), 'amber', 'pause'],
      ['حجم تمام‌شده', $counts->depleted, url()->current().'?'.http_build_query(['status' => 'depleted']), 'red', 'db'],
    ],
    'primary' => auth()->user()->can('mt_accounts.create') ? ['ساخت اکانت '.($proto ? $pl : ''), route('mt.accounts.create', $proto ? ['protocol' => $proto] : [])] : null,
    'secondary' => auth()->user()->can('routers.view') ? ['گروه و تعرفه', route($proto === 'wireguard' ? 'wg.groups' : ($proto === 'l2tp' ? 'l2tp.groups' : 'mt.groups.index')), 'tag'] : null,
  ])
  @if((int) $counts->problems > 0)
    <div class="alert alert-warning">{{ fa((int) $counts->problems) }} اکانت در حال ساخت یا ناموفق است. <a href="{{ url()->current().'?'.http_build_query(['status' => 'pending']) }}">در حال ساخت</a> · <a href="{{ url()->current().'?'.http_build_query(['status' => 'failed']) }}">ناموفق</a></div>
  @endif

  <div class="card list-shell">
    <form method="get" class="mb-8 list-filter">
      @php($moreFilters = collect($filters)->except('q')->filter()->isNotEmpty())
      <div class="search-row">
        <label class="search-box"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
          <input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="جستجو (نام کاربری، نام کاربر یا IP)" aria-label="جستجو" enterkeyhint="search"></label>
        <button type="button" @class(['filter-btn', 'on' => $moreFilters]) aria-controls="more-filters" aria-expanded="{{ $moreFilters ? 'true' : 'false' }}" data-toggle="#more-filters" title="فیلترها"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 5h17l-6.5 8v6l-4-2v-4z"/></svg></button>
      </div>
      <div id="more-filters" class="form-grid mt-8" @if(! $moreFilters) hidden @endif>
      <div class="field"><label for="status">وضعیت</label>
        <select id="status" name="status" class="input"><option value="">همه</option>
          @foreach(['online' => 'آنلاین', 'active' => 'فعال', 'not_started' => 'شروع‌نشده', 'expiring' => 'انقضا تا ۳ روز آینده', 'expired' => 'منقضی', 'depleted' => 'حجم تمام‌شده', 'disabled' => 'غیرفعال', 'pending' => 'در حال ساخت', 'failed' => 'ناموفق', 'deleted' => 'حذف‌شده'] as $k => $v)
            <option value="{{ $k }}" @selected(($filters['status'] ?? '') === $k)>{{ $v }}</option>
          @endforeach
        </select></div>
      @unless($proto)<div class="field"><label for="protocol">پروتکل</label>
        <select id="protocol" name="protocol" class="input"><option value="">همه</option>
          @foreach(\App\Models\MtGroup::PROTOCOLS as $k => $v)<option value="{{ $k }}" @selected(($filters['protocol'] ?? '') === $k)>{{ $v }}</option>@endforeach
        </select></div>@endunless
      <div class="field"><label for="group">گروه</label>
        <select id="group" name="group" class="input"><option value="">همه</option>
          @foreach($groups as $g)<option value="{{ $g->id }}" @selected((string) ($filters['group'] ?? '') === (string) $g->id)>{{ $g->name }} ({{ $g->router?->name }})</option>@endforeach
        </select></div>
      <div class="field"><label for="creator">ساخته‌شده توسط</label>
        <select id="creator" name="creator" class="input"><option value="">همه مدیران</option>
          @foreach($creators as $c)<option value="{{ $c->id }}" @selected((string) ($filters['creator'] ?? '') === (string) $c->id)>{{ $c->name }} ({{ $c->username }})</option>@endforeach
        </select></div>
      <div class="actions"><button class="btn" type="submit">اعمال</button>@if(array_filter($filters))<a class="btn btn-secondary" href="{{ url()->current() }}">حذف فیلتر</a>@endif</div>
      </div>
    </form>

    @if($accounts->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>{{ array_filter($filters) ? 'نتیجه‌ای یافت نشد.' : 'هنوز اکانت '.$pl.' ساخته نشده است.' }}</div></div>
    @else
      <div class="table-wrap">
        <div class="acc-list only-mobile">
          @foreach($accounts as $a)@include('mt.accounts._card', ['a' => $a, 'url' => route('mt.accounts.show', $a)])@endforeach
        </div>
        <table class="table only-desktop">
          <thead><tr><th>نام کاربری</th><th>پروتکل / گروه</th><th>سازنده</th><th>وضعیت</th><th>انقضا</th><th>حجم باقی‌مانده</th><th>مصرف</th><th>آخرین اتصال</th></tr></thead>
          <tbody>
          @foreach($accounts as $a)
            @php($lbl = $a->statusLabel())
            <tr>
              <td><a href="{{ route('mt.accounts.show', $a) }}"><bdi class="ltr">{{ $a->username }}</bdi></a>@if($a->is_online) <span class="badge badge-success">آنلاین</span>@endif @if($a->comment)<div class="small">👤 {{ $a->comment }}</div>@endif
</td>
              <td>{{ $a->protocolLabel() }}<div class="muted small">{{ $a->group?->name }}</div></td>
              <td class="small">{{ $a->creatorLabel() }}</td>
              <td><span class="badge badge-{{ $lbl[1] }}">{{ $lbl[0] }}</span></td>
              <td>{{ $a->expires_at ? jdate($a->expires_at, 'Y/m/d') : ($a->duration_days ? fa($a->duration_days).' روز از اولین اتصال' : '∞') }}
                @include('accounts._mini', ['m' => $a->timeMeter()])</td>
              <td>{{ $a->isVolumeLimited() ? fa_bytes($a->remainingBytes()) : 'نامحدود' }} @include('accounts._mini', ['m' => $a->volumeMeter()])</td>
              <td>{{ fa_bytes($a->used_bytes) }}</td>
              <td class="small">{{ $a->is_online ? 'هم‌اکنون' : ($a->last_seen_at ? fa_ago($a->last_seen_at) : '—') }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      {{ $accounts->links() }}
    @endif
  </div>
</x-layouts.app>
