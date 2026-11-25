{{-- Inline mini bar for table cells. $m = meter array or null --}}
@if($m)<progress class="mini-bar lvl-{{ \App\Models\VpnAccount::meterLevel($m['pct']) }}" max="100" value="{{ $m['pct'] }}">{{ $m['pct'] }}%</progress>@endif
