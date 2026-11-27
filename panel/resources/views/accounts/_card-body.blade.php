@php
  $lvl = fn ($p) => \App\Models\VpnAccount::meterLevel((int) $p);
  $ico = [
    'user' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>',
    'tool' => '<svg viewBox="0 0 24 24"><path d="M14.5 6.5a4 4 0 0 0 5 5L13 18a2.1 2.1 0 0 1-3-3l6.5-6.5zM4 20l5-5"/></svg>',
    'wifi' => '<svg viewBox="0 0 24 24"><path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.4a9.5 9.5 0 0 1 13 0M8.6 15.7a5 5 0 0 1 6.8 0"/><circle cx="12" cy="19" r="1.4"/></svg>',
    'clock' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'cal' => '<svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>',
    'rocket' => '<svg viewBox="0 0 24 24"><path d="M5 15c-1.5 1.5-2 5-2 5s3.5-.5 5-2M9 13l2 2M14.5 4.5C18 3 21 3 21 3s0 3-1.5 6.5L13 16l-5-5z"/><circle cx="15.5" cy="8.5" r="1.5"/></svg>',
    'db' => '<svg viewBox="0 0 24 24"><ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/></svg>',
  ];
  $timeText = ! $tm ? (($timeUnlimited ?? false) ? 'نامحدود' : '—') : (! $tm['started'] ? fa_duration($tm['total']) : ($tm['remaining'] > 0 ? fa_duration($tm['remaining']) : 'منقضی'));
@endphp
<a class="acc-card acc-{{ $st }}" href="{{ $url }}">
  <div class="acc-top">
    <x-proto-logo :proto="$proto" :online="$online"/>
    <div class="acc-id">
      <span class="acc-name-row"><bdi class="acc-name ltr">{{ $username }}</bdi><span class="acc-i">{!! $ico['user'] !!}</span></span>
      @if($customer)<span class="acc-customer">{{ $customer }}</span>@endif
      <span class="acc-sub"><span class="acc-i">{!! $ico['tool'] !!}</span><bdi>{{ $sub }}</bdi></span>
    </div>
    <span class="acc-chevron" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M15 6l-6 6 6 6"/></svg></span>
  </div>
  <div class="acc-badges">
    <span class="acc-pill tone-{{ $status[1] }}">@if($st === 'not_started')<b class="tri">▶</b>@else<i></i>@endif{{ $status[0] }}</span>
    @if($online)<span class="acc-pill tone-success"><span class="acc-i">{!! $ico['wifi'] !!}</span>آنلاین{{ $onlineCount > 1 ? ' ×'.fa($onlineCount) : '' }}</span>@endif
  </div>
  <div class="acc-meters">
    <div class="acc-meter">
      <div class="acc-meter-row"><span><span class="acc-i">{!! ! $tm || ! $tm['started'] ? $ico['cal'] : $ico['clock'] !!}</span>زمان</span><strong>{{ $timeText }}</strong></div>
      <div class="bar lvl-{{ $tm ? $lvl($tm['pct']) : 'unlimited' }}"><i data-w="{{ $tm['pct'] ?? 100 }}"></i></div>
    </div>
    <div class="acc-meter">
      <div class="acc-meter-row"><span><span class="acc-i">{!! $ico['db'] !!}</span>حجم</span>
        <strong>@if($vm){{ fa_bytes($vm['remaining']) }}@elseif($volUnlimited)<span class="inf">∞</span> نامحدود@else—@endif</strong></div>
      <div class="bar lvl-{{ $vm ? $lvl($vm['pct']) : 'unlimited' }}"><i data-w="{{ $vm['pct'] ?? 100 }}"></i></div>
    </div>
  </div>
  <div class="acc-foot">
    @if($expires)<span><span class="acc-i">{!! $ico['cal'] !!}</span>انقضا: {{ jdate($expires, 'Y/m/d') }}</span>
    @elseif($notStarted)<span><span class="acc-i">{!! $ico['rocket'] !!}</span>شروع: با اولین اتصال</span>
    @else<span><span class="acc-i">{!! $ico['cal'] !!}</span>بدون انقضا</span>@endif
    <span @class(['acc-stale' => $stale])><span class="acc-i">{!! $ico['clock'] !!}</span>{{ $ago }}</span>
  </div>
</a>
