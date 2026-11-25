<x-layouts.app title="شارژ کیف پول">
  <div class="page-head"><h1>شارژ کیف پول</h1><a class="btn btn-secondary" href="{{ route('portal.ledger') }}">کیف پول</a></div>
  <div class="card">
    <p>موجودی فعلی: <strong>{{ fa_money($wallet->balance) }}</strong></p>
    @if(! $methods)
      <div class="empty"><div>هنوز روش پرداختی فعال نشده است. با مدیر تماس بگیرید.</div></div>
    @else
      <form method="post" action="{{ route('portal.topup.store') }}">
        @csrf
        <div class="form-grid">
          <div class="field"><label for="amount_toman">مبلغ (تومان)</label><input class="input ltr" type="number" id="amount_toman" name="amount_toman" min="1000" required value="{{ old('amount_toman') }}"></div>
          <div class="field"><label for="method">روش پرداخت</label>
            <select class="input" id="method" name="method" required>@foreach($methods as $k => $l)<option value="{{ $k }}" @selected(old('method') === $k)>{{ $l }}</option>@endforeach</select></div>
          @if($networks)
            <div class="field"><label for="network">شبکه تتر (فقط برای USDT)</label>
              <select class="input" id="network" name="network"><option value="">—</option>@foreach($networks as $k => $l)<option value="{{ $k }}" @selected(old('network') === $k)>{{ $l }}</option>@endforeach</select></div>
          @endif
        </div>
        <button class="btn" type="submit">ادامه پرداخت</button>
      </form>
    @endif
  </div>
  @if($recent->isNotEmpty())
    <div class="card">
      <h2>پرداخت‌های اخیر</h2>
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>زمان</th><th>روش</th><th>مبلغ</th><th>وضعیت</th></tr></thead>
        <tbody>
        @foreach($recent as $p)
          <tr>
            <td data-label="زمان"><a href="{{ route('pay.show', $p->uuid) }}">{{ jdate($p->created_at) }}</a></td>
            <td data-label="روش">{{ $p->methodLabel() }}</td>
            <td data-label="مبلغ">{{ fa_money($p->amount_rial) }}</td>
            <td data-label="وضعیت"><span class="badge badge-{{ \App\Models\Payment::STATUS[$p->status][1] }}">{{ \App\Models\Payment::STATUS[$p->status][0] }}</span></td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </div>
  @endif
</x-layouts.app>
