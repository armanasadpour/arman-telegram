{{-- Orange hero banner (reseller pages / resellers admin). art: vpn | wallet --}}
@props(['title', 'text' => null, 'art' => 'vpn', 'action' => null, 'actionUrl' => null])
<section {{ $attributes->merge(['class' => 'hero-banner']) }}>
  <div class="hero-text">
    <h1>{{ $title }}</h1>
    @if($text)<p>{{ $text }}</p>@endif
    {{ $slot }}
    @if($action && $actionUrl)<a class="hero-btn" href="{{ $actionUrl }}"><span>+</span> {{ $action }}</a>@endif
  </div>
  <svg class="hero-art" viewBox="0 0 140 140" aria-hidden="true">
    <defs>
      <linearGradient id="hb-s" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb347"/><stop offset="1" stop-color="#e8590c"/></linearGradient>
      <linearGradient id="hb-p" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff4e6"/><stop offset="1" stop-color="#ffd8a8"/></linearGradient>
    </defs>
    <ellipse cx="70" cy="122" rx="48" ry="11" fill="url(#hb-p)"/><ellipse cx="70" cy="117" rx="48" ry="11" fill="#fffaf3"/>
    @if($art === 'wallet')
      <g class="hero-float"><rect x="28" y="40" width="84" height="62" rx="14" fill="url(#hb-s)" stroke="#fff" stroke-width="4"/><rect x="28" y="40" width="84" height="18" rx="9" fill="#fff" opacity=".25"/>
        <rect x="84" y="62" width="34" height="22" rx="8" fill="#fff"/><circle cx="96" cy="73" r="4.5" fill="#e8590c"/></g>
    @else
      <g class="hero-float"><path d="M70 14 L108 28 V58 C108 84 92 100 70 110 C48 100 32 84 32 58 V28 Z" fill="url(#hb-s)" stroke="#fff" stroke-width="4"/>
        <path d="M52 47a26 26 0 0 1 36 0M58 54a17 17 0 0 1 24 0" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round"/><circle cx="70" cy="62" r="4" fill="#fff"/>
        <text x="70" y="90" text-anchor="middle" font-family="Arial,sans-serif" font-weight="900" font-size="20" fill="#fff">VPN</text></g>
    @endif
  </svg>
</section>
