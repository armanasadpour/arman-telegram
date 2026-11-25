@props(['values', 'money' => false, 'kind' => 'primary', 'height' => 180])
{{-- Bar chart as inline SVG (no external library; colours from CSS). $values: [Y-m-d => number] --}}
@php
  $vals = array_values($values); $keys = array_keys($values); $n = max(1, count($vals));
  $max = max(1, max($vals ?: [0])); $w = 600; $h = (int) $height; $pad = 22; $bw = ($w - 8) / $n;
  $fmt = fn ($v) => $money ? fa_money((int) $v) : fa((int) $v);
  $label = fn ($k) => jdate(\Carbon\CarbonImmutable::parse($k, config('app.display_timezone')), 'm/d');
  $every = max(1, (int) ceil($n / 8));
@endphp
<figure class="chart chart-{{ $kind }}">
  <svg viewBox="0 0 {{ $w }} {{ $h + $pad }}" role="img" aria-label="نمودار" preserveAspectRatio="none">
    <line class="chart-grid" x1="0" x2="{{ $w }}" y1="{{ $h }}" y2="{{ $h }}"/>
    <line class="chart-grid" x1="0" x2="{{ $w }}" y1="{{ $h / 2 }}" y2="{{ $h / 2 }}"/>
    @foreach($vals as $i => $v)
      @php($bh = $v > 0 ? max(2, ($v / $max) * ($h - 8)) : 0)
      <g>
        <title>{{ jdate(\Carbon\CarbonImmutable::parse($keys[$i], config('app.display_timezone')), 'Y/m/d') }}: {{ $fmt($v) }}</title>
        <rect class="chart-hit" x="{{ $w - ($i + 1) * $bw }}" y="0" width="{{ $bw }}" height="{{ $h }}"/>
        <rect class="chart-bar" x="{{ $w - ($i + 1) * $bw + $bw * 0.15 }}" y="{{ $h - $bh }}" width="{{ $bw * 0.7 }}" height="{{ $bh }}" rx="3"/>
      </g>
      @if($i % $every === 0 || $i === $n - 1)
        <text class="chart-x" x="{{ $w - ($i + 0.5) * $bw }}" y="{{ $h + 16 }}" text-anchor="middle">{{ $label($keys[$i]) }}</text>
      @endif
    @endforeach
  </svg>
  <figcaption class="chart-cap"><span>بیشینه روزانه: {{ $fmt($max) }}</span><span>جمع: {{ $fmt(array_sum($vals)) }}</span></figcaption>
</figure>
