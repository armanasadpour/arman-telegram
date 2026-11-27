{{-- Protocol logo for account cards: openvpn | l2tp | wireguard --}}
@props(['proto' => 'openvpn', 'online' => false])
@php($name = ['openvpn' => 'OpenVPN', 'l2tp' => 'L2TP', 'wireguard' => 'WireGuard'][$proto] ?? $proto)
<span {{ $attributes->merge(['class' => 'proto-logo proto-'.$proto.($online ? ' is-online' : '')]) }} title="{{ $name }}">
  <img src="{{ asset('assets/proto/'.$proto.'.png') }}?v={{ config('panel.version') }}" alt="{{ $name }}" width="54" height="54" loading="lazy" decoding="async">
</span>
