{{-- Mobile account card. $a = VpnAccount, $url = link target --}}
@php($st = $a->status())
@php([$stLabel, $stTone] = \App\Models\VpnAccount::STATUS_LABELS[$st])
@php($tm = $a->timeMeter())
@php($vm = $a->volumeMeter())
@include('accounts._card-body', [
  'url' => $url, 'st' => $st, 'proto' => 'openvpn', 'online' => (bool) $a->is_online, 'username' => $a->username,
  'customer' => $a->displayComment(), 'sub' => request()->routeIs('portal.*') ? ($a->group_name ?? '—') : $a->creatorLabel().' · '.($a->group_name ?? '—'),
  'status' => [$stLabel, $stTone], 'onlineCount' => (int) $a->online_sessions,
  'tm' => $tm, 'vm' => $vm, 'volUnlimited' => ! $a->isVolumeLimited(),
  'expires' => $a->expires_at, 'notStarted' => ! $a->expires_at,
  'ago' => $a->last_synced_at ? fa_ago($a->last_synced_at) : 'همگام نشده', 'stale' => $a->isStale(),
])
