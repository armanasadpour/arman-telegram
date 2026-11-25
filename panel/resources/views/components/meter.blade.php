@props(['label', 'pct', 'main', 'sub' => null])
@php($level = \App\Models\VpnAccount::meterLevel((int) $pct))
<div {{ $attributes->merge(['class' => 'meter meter-'.$level]) }}>
  <div class="meter-head">
    <span class="meter-label">{{ $label }}</span>
    <span class="meter-pct">{{ fa((int) $pct) }}٪</span>
  </div>
  <progress class="meter-bar" max="100" value="{{ (int) $pct }}" data-animate aria-label="{{ $label }}">{{ (int) $pct }}%</progress>
  <div class="meter-foot"><strong>{{ $main }}</strong>@if($sub)<span class="muted">{{ $sub }}</span>@endif</div>
</div>
