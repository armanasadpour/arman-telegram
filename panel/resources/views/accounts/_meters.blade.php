@php($tm = $a->timeMeter())
@php($vm = $a->volumeMeter())
@if($tm || $vm)
  <div class="meters {{ $class ?? '' }}">
    @if($tm)
      <x-meter label="زمان باقی‌مانده" :pct="$tm['pct']"
        :main="$tm['started'] ? ($tm['remaining'] > 0 ? fa_duration($tm['remaining']).' مانده' : 'منقضی شده') : fa_duration($tm['total']).' (شروع با اولین اتصال)'"
        :sub="$tm['started'] ? 'از '.fa_duration($tm['total']).' — تا '.jdate($a->expires_at, 'Y/m/d') : null"/>
    @endif
    @if($vm)
      <x-meter label="حجم باقی‌مانده" :pct="$vm['pct']" :main="fa_bytes($vm['remaining']).' مانده'" :sub="'از '.fa_bytes($vm['total']).' — مصرف‌شده '.fa_bytes($vm['used'])"/>
    @elseif($a->charge() && ! $a->isVolumeLimited())
      <div class="meter meter-ok"><div class="meter-head"><span class="meter-label">حجم</span><span class="meter-pct">∞</span></div><progress class="meter-bar" max="100" value="100">100%</progress><div class="meter-foot"><strong>بدون محدودیت حجم</strong></div></div>
    @endif
  </div>
@endif
