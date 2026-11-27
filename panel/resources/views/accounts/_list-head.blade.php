{{-- Accounts list header: title, 4 colored KPI tiles, primary/secondary actions.
     $title, $subtitle, $kpis = [[label, value, url, color, icon]], $primary = [label, url]|null, $secondary = [label, url, icon]|null --}}
@php($ic = [
  'layers' => '<path d="M12 3 3 8l9 5 9-5-9-5z"/><path d="m3 13 9 5 9-5"/>',
  'wifi' => '<path d="M2.5 9a14 14 0 0 1 19 0M5.5 12.4a9.5 9.5 0 0 1 13 0M8.6 15.7a5 5 0 0 1 6.8 0"/><circle cx="12" cy="19" r="1.4" fill="currentColor" stroke="none"/>',
  'pause' => '<circle cx="12" cy="12" r="9"/><path d="M10 9v6M14 9v6"/>',
  'ban' => '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
  'db' => '<ellipse cx="12" cy="6" rx="7.5" ry="3"/><path d="M4.5 6v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3V6M4.5 12v6c0 1.7 3.4 3 7.5 3s7.5-1.3 7.5-3v-6"/>',
  'trash' => '<path d="M4 7h16M9 7V4.5h6V7M6.5 7l1 13h9l1-13M10 11v5M14 11v5"/>',
  'tag' => '<path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.5"/>',
  'server' => '<rect x="3.5" y="4" width="17" height="7" rx="2"/><rect x="3.5" y="13" width="17" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
])
<div class="list-head">
  <span class="list-head-ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic['server'] !!}</svg></span>
  <div><h1>{{ $title }}</h1>@if($subtitle)<p>{{ $subtitle }}</p>@endif</div>
</div>
<div class="kpis mb-16">
  @foreach($kpis as [$label, $value, $url, $color, $icon])
    <a class="kpi-tile k-{{ $color }}" href="{{ $url }}">
      <svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic[$icon] !!}</svg>
      <span class="kpi-label">{{ $label }}</span>
      <strong class="kpi-val">{{ fa((int) $value) }}</strong>
    </a>
  @endforeach
</div>
@if($primary || $secondary)
  <div class="list-actions mb-16">
    @if($primary)<a class="big-btn big-primary" href="{{ $primary[1] }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>{{ $primary[0] }}</a>@endif
    @if($secondary)<a class="big-btn" href="{{ $secondary[1] }}"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $ic[$secondary[2]] !!}</svg>{{ $secondary[0] }}</a>@endif
  </div>
@endif
