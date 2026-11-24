@php([$label, $tone] = \App\Models\VpnAccount::STATUS_LABELS[$a->status()])
<span class="badge badge-{{ $tone }}">{{ $label }}</span>
@if($a->is_online)<span class="badge badge-success">آنلاین{{ $a->online_sessions > 1 ? ' ×'.fa($a->online_sessions) : '' }}</span>@endif
