{{-- Mobile card for a MikroTik account. $a = MtAccount, $url --}}
@php($st = $a->displayStatus())
@php($lbl = $a->statusLabel())
@php($tm = $a->timeMeter())
@php($vm = $a->volumeMeter())
<a class="acc-card acc-{{ $st }}" href="{{ $url }}">
  <div class="acc-top">
    <span @class(['acc-avatar', 'is-online' => $a->is_online])>{{ $a->isWireguard() ? 'W' : 'L' }}</span>
    <div class="acc-id">
      <bdi class="acc-name ltr">{{ $a->username }}</bdi>
      <span class="acc-group">{{ $a->protocolLabel() }} · <bdi>{{ $a->group?->name ?? '—' }}</bdi></span>
    </div>
    <span class="acc-chevron" aria-hidden="true">‹</span>
  </div>
  <div class="acc-badges"><span class="badge badge-{{ $lbl[1] }}">{{ $lbl[0] }}</span>@if($a->is_online)<span class="badge badge-success">آنلاین</span>@endif</div>
  <div class="acc-meters">
    <div class="acc-meter">
      <div class="acc-meter-row"><span>زمان</span>
        <strong>@if(! $tm)نامحدود@elseif(! $tm['started']){{ fa_duration($tm['total']) }}@elseif($tm['remaining'] > 0){{ fa_duration($tm['remaining']) }}@else منقضی@endif</strong></div>
      <progress class="mini-bar lvl-{{ $tm ? \App\Models\VpnAccount::meterLevel($tm['pct']) : 'unlimited' }}" max="100" value="{{ $tm['pct'] ?? 100 }}">{{ $tm['pct'] ?? 100 }}%</progress>
    </div>
    <div class="acc-meter">
      <div class="acc-meter-row"><span>حجم</span><strong>{{ $vm ? fa_bytes($vm['remaining']) : 'نامحدود' }}</strong></div>
      <progress class="mini-bar lvl-{{ $vm ? \App\Models\VpnAccount::meterLevel($vm['pct']) : 'unlimited' }}" max="100" value="{{ $vm['pct'] ?? 100 }}">{{ $vm['pct'] ?? 100 }}%</progress>
    </div>
  </div>
  <div class="acc-foot">
    <span>{{ $a->expires_at ? 'انقضا '.jdate($a->expires_at, 'Y/m/d') : ($a->duration_days ? 'شروع با اولین اتصال' : 'بدون انقضا') }}</span>
    <span>مصرف {{ fa_bytes($a->used_bytes) }}</span>
  </div>
</a>
