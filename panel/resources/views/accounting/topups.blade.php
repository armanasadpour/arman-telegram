<x-layouts.app title="درخواست شارژ حساب">
  <div class="list-head">
    <span class="list-head-ico acc-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="6" width="17" height="12" rx="2.5"/><path d="M3.5 10h17M7 14.5h4"/></svg></span>
    <div><h1>درخواست شارژ حساب</h1><p>واریزهای کارت‌به‌کارت همکاران؛ با تأیید شما کیف پول همکار شارژ می‌شود. ۳۰ روز اخیر: {{ fa_money($paidMonth) }}</p></div>
  </div>
  <div class="seg mb-16">
    @foreach(['review' => 'منتظر تأیید', 'paid' => 'تأییدشده', 'rejected' => 'ردشده', 'all' => 'همه'] as $k => $l)
      <a href="{{ route('accounting.topups', ['status' => $k]) }}" @class(['seg-item', 'on' => $status === $k])>{{ $l }}@if(isset($counts[$k]) && $counts[$k] > 0) <b>{{ fa($counts[$k]) }}</b>@endif</a>
    @endforeach
  </div>
  @can('payments.sensitive')<p class="mb-16"><a class="btn btn-secondary btn-sm" href="{{ route('accounting.cards') }}">کارت‌های بانکی</a></p>@endcan

  @if($rows->isEmpty())
    <div class="card"><div class="empty"><x-icon name="inbox"/><div>{{ $status === 'review' ? 'درخواست منتظری نیست.' : 'موردی نیست.' }}</div></div></div>
  @else
    <div class="req-list">
      @foreach($rows as $p)
        @php($r = $resellers[$p->wallet?->owner_id] ?? null)
        @php([$sl, $st] = \App\Models\Payment::STATUS[$p->status] ?? [$p->status, 'primary'])
        <article class="req-card">
          <header class="req-head">
            <span class="req-avatar">{{ mb_substr($r?->name ?? '؟', 0, 1) }}</span>
            <div class="req-who"><strong>{{ $r?->name ?? 'همکار' }}</strong><small>کد <bdi class="ltr">{{ $r?->code }}</bdi> · {{ jdate($p->created_at, 'Y/m/d H:i') }} ({{ fa_ago($p->created_at) }})</small></div>
            <span class="badge badge-{{ $st }}">{{ $sl }}</span>
          </header>
          <div class="req-body">
            <div class="req-amount"><small>مبلغ</small><strong>{{ fa_money($p->amount_rial) }}</strong>
              @if($p->payer_note)<small class="muted">{{ $p->payer_note }}</small>@endif
              @if($p->status !== 'review' && $p->reviewer)<small class="muted">بررسی: {{ $p->reviewer->name }}{{ $p->review_reason && $p->review_reason !== 'از پنل' ? ' — '.$p->review_reason : '' }}</small>@endif</div>
            @if($p->receipt_path)
              <a class="req-receipt" href="{{ route('payments.receipt', $p) }}" target="_blank" rel="noopener"><img src="{{ route('payments.receipt', $p) }}" alt="فیش" loading="lazy"><span>مشاهده فیش</span></a>
            @endif
          </div>
          @if($p->status === 'review')
            @can('payments.sensitive')
              <div class="req-actions">
                <form method="post" action="{{ route('payments.approve', $p) }}" data-confirm="واریز {{ fa_money($p->amount_rial) }} تأیید و کیف پول «{{ $r?->name }}» شارژ شود؟" data-confirm-ok="تأیید و شارژ">@csrf<button class="btn btn-success w-full" type="submit">✓ تأیید و شارژ کیف پول</button></form>
                <form method="post" action="{{ route('payments.reject', $p) }}" data-confirm="این درخواست رد شود؟" data-confirm-ok="رد کن">@csrf<button class="btn btn-danger w-full" type="submit">رد درخواست</button></form>
              </div>
            @endcan
          @endif
        </article>
      @endforeach
    </div>
    {{ $rows->links() }}
  @endif
</x-layouts.app>
