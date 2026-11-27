{{-- Remaining days + remaining volume as two animated rings (account pages, admin and portal). --}}
@props(['tm' => null, 'vm' => null, 'used' => 0, 'volumeUnlimited' => false, 'status' => null, 'expiresAt' => null])
@php
  $gb = 1073741824;
  // days ring
  if ($tm) {
      $daysPct = (int) $tm['pct'];
      $remDays = $tm['remaining'] / 86400;
      $totDays = max(1, (int) round($tm['total'] / 86400));
      if (! $tm['started']) { $dVal = $totDays; $dDec = 0; $dUnit = 'روز'; $dSub = 'شروع با اولین اتصال'; }
      elseif ($tm['remaining'] <= 0) { $dVal = 0; $dDec = 0; $dUnit = 'منقضی شده'; $dSub = 'از '.fa($totDays).' روز'; }
      elseif ($remDays < 1) { $dVal = (int) ceil($tm['remaining'] / 3600); $dDec = 0; $dUnit = 'ساعت'; $dSub = 'از '.fa($totDays).' روز'; }
      else { $dVal = (int) floor($remDays); $dDec = 0; $dUnit = 'روز'; $dSub = 'از '.fa($totDays).' روز'; }
  }
  // volume ring
  if ($vm) {
      $volPct = (int) $vm['pct'];
      $small = $vm['remaining'] < $gb;
      $vVal = $small ? $vm['remaining'] / 1048576 : $vm['remaining'] / $gb;
      $vDec = $small ? 0 : 2;
      $vUnit = $vm['remaining'] <= 0 ? 'تمام شده' : ($small ? 'مگابایت' : 'گیگابایت');
      $tot = $vm['total'] / $gb;
      $totS = $tot < 10 ? rtrim(rtrim(number_format($tot, 1, '.', ''), '0'), '.') : number_format($tot, 0, '.', '');
      $vSub = 'از '.str_replace('.', '٫', fa($totS)).' گیگابایت';
  }
  $lvl = fn (int $p) => $p <= 15 ? 'low' : ($p <= 40 ? 'warn' : 'ok');
  $C = 2 * M_PI * 52;   // ring circumference (r = 52)
@endphp
@if($tm || $vm || $volumeUnlimited)
<section {{ $attributes->merge(['class' => 'rings-card']) }}>
  @if($status)
    <span class="rings-status tone-{{ $status[1] }}"><i></i>{{ $status[0] }}</span>
  @endif
  <div class="rings">
    {{-- days (right in RTL) --}}
    <div class="ring ring-days lvl-{{ $tm ? $lvl($daysPct) : 'ok' }}">
      <div class="ring-dial">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <defs><linearGradient id="rg-days" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--ring-days-a)"/><stop offset="1" stop-color="var(--ring-days-b)"/></linearGradient></defs>
        <circle class="ring-track" cx="60" cy="60" r="52"/>
        <circle class="ring-arc" cx="60" cy="60" r="52" stroke="url(#rg-days)" stroke-dasharray="{{ $C }}" stroke-dashoffset="{{ $C }}" data-ring="{{ $tm ? $daysPct : 100 }}" data-c="{{ $C }}"/>
      </svg>
      <div class="ring-body">
        <svg class="ring-ico" viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/><circle cx="8.5" cy="14" r=".9"/><circle cx="12" cy="14" r=".9"/><circle cx="15.5" cy="14" r=".9"/><circle cx="8.5" cy="17" r=".9"/><circle cx="12" cy="17" r=".9"/></svg>
        <span class="ring-label">{{ $tm && $tm['started'] ? 'روز باقی‌مانده' : 'مدت اعتبار' }}</span>
        @if($tm)
          <strong class="ring-num" data-count="{{ $dVal }}" data-dec="{{ $dDec }}">{{ fa($dVal) }}</strong>
          <span class="ring-unit">{{ $dUnit }}</span>
          <span class="ring-pill">{{ $dSub }}</span>
        @else
          <strong class="ring-num">∞</strong><span class="ring-unit">بدون محدودیت</span>
        @endif
      </div>
      </div>
    </div>
    {{-- volume (left in RTL) --}}
    <div class="ring ring-vol lvl-{{ $vm ? $lvl($volPct) : 'ok' }}">
      <div class="ring-dial">
      <svg viewBox="0 0 120 120" aria-hidden="true">
        <defs><linearGradient id="rg-vol" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="var(--ring-vol-a)"/><stop offset="1" stop-color="var(--ring-vol-b)"/></linearGradient></defs>
        <circle class="ring-track" cx="60" cy="60" r="52"/>
        <circle class="ring-arc" cx="60" cy="60" r="52" stroke="url(#rg-vol)" stroke-dasharray="{{ $C }}" stroke-dashoffset="{{ $C }}" data-ring="{{ $vm ? $volPct : 100 }}" data-c="{{ $C }}"/>
      </svg>
      <div class="ring-body">
        <svg class="ring-ico" viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/></svg>
        <span class="ring-label">حجم باقی‌مانده</span>
        @if($vm)
          <strong class="ring-num" data-count="{{ round($vVal, $vDec) }}" data-dec="{{ $vDec }}">{{ str_replace('.', '٫', fa(number_format($vVal, $vDec, '.', ''))) }}</strong>
          <span class="ring-unit">{{ $vUnit }}</span>
          <span class="ring-pill">{{ $vSub }}</span>
        @else
          <strong class="ring-num">∞</strong><span class="ring-unit">بدون محدودیت حجم</span>
        @endif
      </div>
      </div>
    </div>
  </div>
  <div class="rings-foot">
    <span><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>{{ $tm && $tm['started'] && $expiresAt ? jdate($expiresAt, 'Y/m/d – H:i') : ($tm ? 'از اولین اتصال' : 'بدون تاریخ انقضا') }}</span>
    <span><svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/></svg>مصرف‌شده: {{ fa_bytes((int) $used) }}</span>
  </div>
  <svg class="rings-wave" viewBox="0 0 600 80" preserveAspectRatio="none" aria-hidden="true">
    <path class="w1" d="M0 40 C 100 10 200 70 300 40 S 500 10 600 40 V80 H0Z"/>
    <path class="w2" d="M0 50 C 120 80 220 20 330 50 S 520 80 600 45 V80 H0Z"/>
  </svg>
</section>
@endif
