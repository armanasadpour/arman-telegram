<x-layouts.app title="کیف پول من">
  <section class="wallet-hero mb-16">
    <div class="wh-top"><span>موجودی کیف پول</span>
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><rect x="3.5" y="7.5" width="17" height="12" rx="2.5"/><path d="M16 13.5h2"/></svg></div>
    <strong class="wh-balance">{{ fa_money($wallet->balance) }}</strong>
    <div class="wh-actions">
      <a class="wh-btn wh-primary" href="{{ route('portal.topup') }}"><span>+</span> شارژ کیف پول</a>
      <a class="wh-btn" href="{{ route('portal.export', 'ledger') }}">خروجی اکسل</a>
    </div>
  </section>

  <section class="card">
    <h2>تراکنش‌ها</h2>
    @if($entries->isEmpty())<div class="empty"><div>هنوز تراکنشی ندارید.</div></div>@else
      <div class="tx-list">
        @foreach($entries as $e)
          <div class="tx-row">
            <span @class(['tx-ico', 'tone-success' => $e->amount >= 0, 'tone-danger' => $e->amount < 0])>
              <svg viewBox="0 0 24 24" aria-hidden="true">@if($e->amount >= 0)<path d="M12 19V5M6 11l6-6 6 6"/>@else<path d="M12 5v14M6 13l6 6 6-6"/>@endif</svg></span>
            <span class="tx-main"><strong>{{ $e->typeLabel() }}</strong><small>{{ $e->description }}</small><small>{{ jdate($e->created_at, 'Y/m/d H:i') }} · موجودی پس از: {{ fa_money($e->balance_after) }}</small></span>
            <span @class(['tx-amount', 'neg' => $e->amount < 0])>{{ $e->amount < 0 ? '−' : '+' }}{{ fa_money(abs($e->amount)) }}</span>
          </div>
        @endforeach
      </div>
      {{ $entries->links() }}
    @endif
  </section>
</x-layouts.app>
