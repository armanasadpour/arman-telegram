{{-- Mobile card for a MikroTik account. $a = MtAccount, $url --}}
@include('accounts._card-body', [
  'url' => $url, 'st' => $a->displayStatus(), 'proto' => $a->isWireguard() ? 'wireguard' : 'l2tp', 'online' => (bool) $a->is_online, 'username' => $a->username,
  'customer' => $a->comment, 'sub' => request()->routeIs('portal.*') ? ($a->group?->name ?? '—') : $a->creatorLabel().' · '.($a->group?->name ?? '—'),
  'status' => $a->statusLabel(), 'onlineCount' => (int) $a->online_count,
  'tm' => $a->timeMeter(), 'vm' => $a->volumeMeter(), 'volUnlimited' => ! $a->isVolumeLimited(), 'timeUnlimited' => ! $a->duration_days,
  'expires' => $a->expires_at, 'notStarted' => ! $a->expires_at && $a->duration_days,
  'ago' => $a->last_seen_at ? fa_ago($a->last_seen_at) : 'هنوز وصل نشده', 'stale' => false,
])
