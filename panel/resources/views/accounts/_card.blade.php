{{-- Mobile account card. $a = VpnAccount, $url = link target --}}
@php($st = $a->status())
@php($tm = $a->timeMeter())
@php($vm = $a->volumeMeter())
<a class="acc-card acc-{{ $st }}" href="{{ $url }}">
  <div class="acc-top">
    <span @class(['acc-avatar', 'is-online' => $a->is_online])>{{ mb_strtoupper(mb_substr($a->username, 0, 1)) }}</span>
    <div class="acc-id">
      <bdi class="acc-name ltr">{{ $a->username }}</bdi>
      <span class="acc-group"><bdi>{{ $a->group_name ?? '—' }}</bdi></span>
    </div>
    <span class="acc-chevron" aria-hidden="true">‹</span>
  </div>
  <div class="acc-badges">@include('accounts._status', ['a' => $a])</div>
  <div class="acc-meters">
    <div class="acc-meter">
      <div class="acc-meter-row"><span>زمان</span>
        <strong>@if(! $tm)—@elseif(! $tm['started']){{ fa_duration($tm['total']) }}@elseif($tm['remaining'] > 0){{ fa_duration($tm['remaining']) }}@else منقضی@endif</strong></div>
      <progress class="mini-bar lvl-{{ $tm ? \App\Models\VpnAccount::meterLevel($tm['pct']) : 'none' }}" max="100" value="{{ $tm['pct'] ?? 0 }}">{{ $tm['pct'] ?? 0 }}%</progress>
    </div>
    <div class="acc-meter">
      <div class="acc-meter-row"><span>حجم</span><strong>{{ $vm ? fa_bytes($vm['remaining']) : ($a->isVolumeLimited() ? '—' : 'نامحدود') }}</strong></div>
      <progress class="mini-bar lvl-{{ $vm ? \App\Models\VpnAccount::meterLevel($vm['pct']) : 'unlimited' }}" max="100" value="{{ $vm['pct'] ?? 100 }}">{{ $vm['pct'] ?? 100 }}%</progress>
    </div>
  </div>
  <div class="acc-foot">
    <span>{{ $a->expires_at ? 'انقضا '.jdate($a->expires_at, 'Y/m/d') : 'شروع با اولین اتصال' }}</span>
    <span @class(['acc-stale' => $a->isStale()])>{{ $a->last_synced_at ? fa_ago($a->last_synced_at) : 'همگام نشده' }}</span>
  </div>
</a>
