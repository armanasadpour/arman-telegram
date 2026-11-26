{{-- Connection card: green when online (tap = disconnect all sessions, after a confirm), red when offline.
     $online bool, $main text, $hint text, $kickUrl ?string (null = not clickable) --}}
@if($online && $kickUrl)
  <form method="post" action="{{ $kickUrl }}" class="conn-form" data-confirm="همه نشست‌های فعال این اکانت آفلاین می‌شوند." data-confirm-ok="باشه" data-confirm-cancel="منصرف شدم">
    @csrf
    <button type="submit" class="card stat conn-card is-online" title="قطع همه نشست‌ها">
      <span class="label">اتصال <span class="conn-pulse" aria-hidden="true"></span></span>
      <span class="value small">{{ $main }}</span>
      <span class="hint"><bdi>{{ $hint }}</bdi></span>
      <span class="conn-tap">⛔ برای قطع همه نشست‌ها بزنید</span>
    </button>
  </form>
@else
  <div @class(['card', 'stat', 'conn-card', 'is-online' => $online, 'is-offline' => ! $online])>
    <div class="label">اتصال @if($online)<span class="conn-pulse" aria-hidden="true"></span>@endif</div>
    <div class="value small">{{ $main }}</div>
    <div class="hint"><bdi>{{ $hint }}</bdi></div>
  </div>
@endif
