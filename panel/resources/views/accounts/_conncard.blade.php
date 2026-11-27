{{-- Connection card: green when online (tap = disconnect all sessions, after a confirm), red when offline.
     $online bool, $main text, $hint text, $hint2 ?text, $kickUrl ?string (null = not clickable) --}}
@php($wifi = '<svg class="conn-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.4a9.5 9.5 0 0 1 13 0M8.6 15.7a5 5 0 0 1 6.8 0"/><circle cx="12" cy="19" r="1.6"/></svg>')
@php($user = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg>')
@php($mon = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>')
@php($tag = $online && $kickUrl ? 'button' : 'div')
@if($online && $kickUrl)
  <form method="post" action="{{ $kickUrl }}" class="conn-form" data-confirm="همه نشست‌های فعال این اکانت آفلاین می‌شوند." data-confirm-ok="باشه" data-confirm-cancel="منصرف شدم">
    @csrf
@endif
<{{ $tag }} @if($tag === 'button') type="submit" title="قطع همه نشست‌ها" @endif @class(['card', 'stat', 'conn-card', 'is-online' => $online, 'is-offline' => ! $online])>
  <span class="conn-wave" aria-hidden="true"></span>
  <span class="conn-badge">{!! $wifi !!}</span>
  <span class="label">اتصال @if($online)<span class="conn-pulse" aria-hidden="true"></span>@endif</span>
  <span class="value">{{ $main }}</span>
  @if(! empty($hint))<span class="hint">{!! $user !!}<bdi>{{ $hint }}</bdi></span>@endif
  @if(! empty($hint2))<span class="hint">{!! $mon !!}<bdi>{{ $hint2 }}</bdi></span>@endif
</{{ $tag }}>
@if($online && $kickUrl)
  </form>
@endif
