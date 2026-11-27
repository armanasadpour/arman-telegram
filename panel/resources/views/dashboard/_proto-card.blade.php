{{-- Protocol card (admin and reseller dashboards). $key = openvpn|l2tp|wireguard, $c = [title, url, create, active, online, expired, used?, today?]; needs $di icons --}}
@php($di = \App\Support\DashIcons::all())
<section class="pdash-card pd-{{ $key }}">
  <header class="pdash-head">
    <a class="pdash-id" href="{{ $c['url'] }}">
      <x-proto-logo :proto="$key"/>
      <span class="pdash-title"><strong>{{ $c['title'] }}</strong><small>پروتکل {{ $c['title'] }}</small></span>
    </a>
    @if($c['create'])<a class="pdash-new" href="{{ $c['create'] }}"><span>+</span> اکانت جدید</a>@endif
  </header>
  <a @class(['pdash-stats', 'three' => ! array_key_exists('used', $c)]) href="{{ $c['url'] }}">
    <span class="pds"><svg class="ic-users" viewBox="0 0 24 24" aria-hidden="true">{!! $di['users'] !!}</svg><span class="pds-l">فعال</span><strong class="pds-v">{{ fa($c['active']) }}</strong></span>
    <span class="pds"><svg class="ic-wifi" viewBox="0 0 24 24" aria-hidden="true">{!! $di['wifi'] !!}</svg><span class="pds-l">آنلاین</span><strong class="pds-v">{{ fa($c['online']) }}<i class="pds-dot"></i></strong></span>
    <span class="pds"><svg class="ic-pause" viewBox="0 0 24 24" aria-hidden="true">{!! $di['pause'] !!}</svg><span class="pds-l">منقضی</span><strong class="pds-v">{{ fa($c['expired']) }}</strong></span>
    @if(array_key_exists('used', $c))
      <span class="pds pds-wide"><svg class="ic-db" viewBox="0 0 24 24" aria-hidden="true">{!! $di['db'] !!}</svg><span class="pds-l">مصرف دیتا</span><strong class="pds-v pds-sm">{{ fa_bytes($c['used']) }}</strong>@if($c['today'] !== null)<small class="pds-sub">امروز {{ fa_bytes($c['today']) }}</small>@endif</span>
    @endif
  </a>
</section>
