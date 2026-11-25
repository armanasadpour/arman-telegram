<x-layouts.app title="کیف پول من">
  <div class="page-head"><div><h1>کیف پول من</h1><p class="muted">موجودی: {{ fa_money($wallet->balance) }} — برای شارژ کیف پول با مدیر تماس بگیرید.</p></div></div>
  <div class="card">
    @if($entries->isEmpty())<div class="empty"><div>هنوز تراکنشی ندارید.</div></div>@else
    <div class="table-wrap"><table class="table stack">
      <thead><tr><th>شناسه</th><th>زمان</th><th>نوع</th><th>مبلغ</th><th>موجودی پس از</th><th>شرح</th></tr></thead>
      <tbody>
      @foreach($entries as $e)
        <tr><td data-label="شناسه">{{ fa((string) $e->id) }}</td><td data-label="زمان">{{ jdate($e->created_at, 'Y/m/d H:i') }}</td><td data-label="نوع">{{ $e->typeLabel() }}</td>
          <td data-label="مبلغ" @class(['text-danger' => $e->amount < 0])>{{ $e->amount < 0 ? '−' : '+' }}{{ fa_money(abs($e->amount)) }}</td><td data-label="موجودی پس از">{{ fa_money($e->balance_after) }}</td>
          <td data-label="شرح">{{ $e->description }} @if($e->reason)<div class="muted small">{{ $e->reason }}</div>@endif</td></tr>
      @endforeach
      </tbody>
    </table></div>
    {{ $entries->links() }}
    @endif
  </div>
</x-layouts.app>
