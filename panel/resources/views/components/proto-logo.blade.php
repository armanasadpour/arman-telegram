{{-- Protocol mark for account cards: openvpn | l2tp | wireguard (simplified marks in each protocol's colors). --}}
@props(['proto' => 'openvpn', 'online' => false])
<span {{ $attributes->merge(['class' => 'proto-logo proto-'.$proto.($online ? ' is-online' : '')]) }} title="{{ ['openvpn' => 'OpenVPN', 'l2tp' => 'L2TP', 'wireguard' => 'WireGuard'][$proto] ?? $proto }}">
  @if($proto === 'wireguard')
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <circle cx="24" cy="24" r="16.5" fill="none" stroke="#fff" stroke-width="2.2" stroke-opacity=".9"/>
      <path d="M21 16.5c6.5-1.6 11.5 3 7.6 7.4-3.6 4-11.6 2.4-11.2 7.3.4 4.3 8.6 4.6 12.6 1.4" fill="none" stroke="#fff" stroke-width="3.6" stroke-linecap="round"/>
      <ellipse cx="18.6" cy="16.6" rx="3.9" ry="3.2" fill="#fff"/>
      <circle cx="17.6" cy="16" r="1" fill="#8a1a1d"/>
    </svg>
  @elseif($proto === 'l2tp')
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <path d="M10 30a14 14 0 0 1 28 0" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="3" stroke-linecap="round"/>
      <rect x="16.5" y="22" width="15" height="13" rx="3" fill="#fff"/>
      <path d="M19.5 22v-3.2a4.5 4.5 0 0 1 9 0V22" fill="none" stroke="#fff" stroke-width="3"/>
      <circle cx="24" cy="28.3" r="1.9" fill="#2563eb"/>
    </svg>
  @else
    <svg viewBox="0 0 48 48" aria-hidden="true">
      <circle cx="24" cy="24" r="14.5" fill="#fff"/>
      <path d="M24 15.5a6.2 6.2 0 0 0-3.1 11.6L19 33.5h10l-1.9-6.4A6.2 6.2 0 0 0 24 15.5z" fill="#1b2a4e"/>
    </svg>
  @endif
</span>
