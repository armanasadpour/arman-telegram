<x-layouts.app title="گزارش‌ها">
  <div class="page-head">
    <div><h1>گزارش‌ها</h1><p class="muted">از {{ jdate($from, 'Y/m/d') }} تا {{ jdate($to, 'Y/m/d') }}</p></div>
  </div>
  <div class="card">
    <form method="get" class="form-grid">
      <div class="field"><label for="from">از تاریخ</label><input class="input ltr" id="from" name="from" data-jdate readonly value="{{ $q['from'] }}"></div>
      <div class="field"><label for="to">تا تاریخ</label><input class="input ltr" id="to" name="to" data-jdate readonly value="{{ $q['to'] }}"></div>
      <div class="actions"><button class="btn" type="submit">نمایش</button>
        <a class="btn btn-secondary" href="{{ route('reports.index', ['from' => jdate(now()->subDays(6), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')]) }}">۷ روز</a>
        <a class="btn btn-secondary" href="{{ route('reports.index') }}">۳۰ روز</a>
        <a class="btn btn-secondary" href="{{ route('reports.index', ['from' => jdate(now()->subDays(89), 'Y/m/d'), 'to' => jdate(now(), 'Y/m/d')]) }}">۹۰ روز</a></div>
    </form>
  </div>

  <div class="kpis mb-16">
    <div class="kpi"><div class="label">درآمد فروش</div><div class="value">{{ fa_money($totals['sales_rial'] + $totals['orders_rial']) }}</div><div class="hint">همکاران + خرید مستقیم</div></div>
    <div class="kpi"><div class="label">فروش همکاران</div><div class="value">{{ fa($totals['sales_count']) }}</div><div class="hint">{{ fa_money($totals['sales_rial']) }} — سود همکاران {{ fa_money($totals['reseller_profit']) }}</div></div>
    <div class="kpi"><div class="label">خرید مستقیم مشتریان</div><div class="value">{{ fa($totals['orders_count']) }}</div><div class="hint">{{ fa_money($totals['orders_rial']) }}</div></div>
    <div class="kpi"><div class="label">پرداخت‌های دریافتی</div><div class="value">{{ fa_money($totals['payments_rial']) }}</div><div class="hint">شارژ دستی: {{ fa_money($totals['manual_credit']) }}</div></div>
    <div class="kpi"><div class="label">اکانت جدید</div><div class="value">{{ fa($totals['new_accounts']) }}</div><div class="hint">ساخته‌شده از پنل/ربات</div></div>
  </div>

  <div class="charts mb-16">
    <div class="card"><h2>درآمد فروش روزانه</h2><x-chart :values="$daily['revenue']" money kind="success"/></div>
    <div class="card"><h2>پرداخت‌های دریافتی روزانه</h2><x-chart :values="$daily['payments']" money kind="violet"/></div>
    <div class="card"><h2>تعداد فروش روزانه (همکاران + مستقیم)</h2><x-chart :values="$daily['count']"/></div>
    <div class="card"><h2>اکانت‌های جدید روزانه</h2><x-chart :values="$daily['accounts']" kind="warn"/></div>
  </div>

  <div class="card">
    <h2>پرداخت‌ها به تفکیک روش</h2>
    @if(! $totals['payments_by_method'])<p class="muted">پرداختی در این بازه نیست.</p>@else
    <div class="table-wrap"><table class="table stack"><thead><tr><th>روش</th><th>تعداد</th><th>مبلغ</th></tr></thead><tbody>
      @foreach($totals['payments_by_method'] as $m => $r)
        <tr><td data-label="روش">{{ \App\Models\Payment::METHODS[$m] ?? $m }}</td><td data-label="تعداد">{{ fa($r['count']) }}</td><td data-label="مبلغ">{{ fa_money($r['rial']) }}</td></tr>
      @endforeach
    </tbody></table></div>
    @endif
  </div>

  <div class="card">
    <h2>عملکرد همکاران</h2>
    @if($resellers->isEmpty())<p class="muted">همکاری تعریف نشده است.</p>@else
    <div class="table-wrap"><table class="table stack"><thead><tr><th>همکار</th><th>تعداد فروش</th><th>مبلغ فروش</th><th>سود همکار</th><th>موجودی</th><th>اکانت‌ها</th></tr></thead><tbody>
      @foreach($resellers as $r)
        <tr>
          <td data-label="همکار">@can('resellers.view')<a href="{{ route('resellers.show', $r['reseller']) }}">{{ $r['reseller']->name }}</a>@else{{ $r['reseller']->name }}@endcan <span class="muted small">{{ $r['reseller']->code }}</span></td>
          <td data-label="تعداد فروش">{{ fa($r['sales']) }}</td><td data-label="مبلغ فروش">{{ fa_money($r['rial']) }}</td><td data-label="سود">{{ fa_money($r['profit']) }}</td>
          <td data-label="موجودی">{{ fa_money($r['balance']) }}</td><td data-label="اکانت‌ها">{{ fa($r['accounts']) }}</td>
        </tr>
      @endforeach
    </tbody></table></div>
    @endif
  </div>

  <div class="card">
    <h2>اکانت‌ها به تفکیک گروه (وضعیت فعلی)</h2>
    <div class="table-wrap"><table class="table stack"><thead><tr><th>گروه</th><th>کل</th><th>آنلاین</th><th>منقضی</th></tr></thead><tbody>
      @foreach($groups as $g)
        <tr><td data-label="گروه"><bdi>{{ $g->group_name ?? '—' }}</bdi></td><td data-label="کل">{{ fa((int) $g->total) }}</td><td data-label="آنلاین">{{ fa((int) $g->online) }}</td><td data-label="منقضی">{{ fa((int) $g->expired) }}</td></tr>
      @endforeach
    </tbody></table></div>
  </div>

  @can('reports.export')
    <div class="card">
      <h2>خروجی اکسل</h2>
      <p class="muted small">فایل CSV با تاریخ شمسی و مبالغ به تومان؛ مستقیم در Excel باز می‌شود. بازه تاریخ همین صفحه اعمال می‌شود (فهرست اکانت‌ها: وضعیت فعلی).</p>
      <div class="actions">
        @foreach(['sales' => 'فروش همکاران', 'orders' => 'خرید مستقیم', 'payments' => 'پرداخت‌ها', 'ledger' => 'دفتر کل کیف پول‌ها', 'resellers' => 'عملکرد همکاران', 'accounts' => 'فهرست اکانت‌ها'] as $t => $l)
          <a class="btn btn-secondary" href="{{ route('reports.export', ['type' => $t] + $q) }}">{{ $l }}</a>
        @endforeach
      </div>
    </div>
  @endcan
</x-layouts.app>
