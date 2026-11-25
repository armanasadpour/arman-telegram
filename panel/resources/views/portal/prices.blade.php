<x-layouts.app title="بسته‌ها و قیمت من">
  <div class="page-head"><h1>بسته‌ها و قیمت خرید من</h1></div>
  <div class="card">
    @if($rows->isEmpty())<div class="empty"><div>بسته فعالی وجود ندارد.</div></div>@else
    <div class="table-wrap"><table class="table stack">
      <thead><tr><th>بسته</th><th>نوع</th><th>مشخصات</th><th>قیمت عمومی</th><th>قیمت خرید من</th></tr></thead>
      <tbody>
      @foreach($rows as $row)
        <tr><td data-label="بسته">{{ $row['p']->name }}</td><td data-label="نوع">{{ $row['p']->typeLabel() }}</td><td data-label="مشخصات">{{ $row['p']->summary() }}</td>
          <td data-label="قیمت عمومی">{{ fa_money((int) $row['p']->price_rial) }}</td><td data-label="قیمت من"><strong>{{ fa_money($row['q']->finalPrice) }}</strong></td></tr>
      @endforeach
      </tbody>
    </table></div>
    @endif
  </div>
</x-layouts.app>
