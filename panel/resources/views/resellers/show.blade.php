<x-layouts.app title="همکار {{ $r->name }}">
  <div class="page-head">
    <div><h1>{{ $r->name }} <bdi class="muted ltr small">{{ $r->code }}</bdi></h1>
      <p class="muted">نام کاربری: <bdi class="ltr">{{ $r->user->username }}</bdi> — @if($r->isActive())<span class="badge badge-success">فعال</span>@else<span class="badge badge-warning">تعلیق (فروش ممکن نیست)</span>@endif</p></div>
    <a class="btn btn-secondary" href="{{ route('resellers.index') }}">بازگشت</a>
  </div>

  <div class="grid stats mb-16">
    <div class="card stat"><div class="label">موجودی کیف پول</div><div class="value small">{{ fa_money($wallet->balance) }}</div>
      @if($recon)<div class="hint">@if($recon['ok'])تطبیق با دفتر کل: درست ({{ fa($recon['entries']) }} ردیف)@else<span class="text-danger">عدم تطبیق! دفتر کل: {{ fa_money($recon['ledger_sum']) }}</span>@endif</div>@endif
    </div>
  </div>

  <div class="grid two">
    @can('wallets.sensitive')
      <div class="card">
        <h2>شارژ یا کسر دستی</h2>
        <form method="post" action="{{ route('resellers.adjust', $r) }}" data-confirm="تراکنش ثبت شود؟ ردیف دفتر کل قابل ویرایش یا حذف نیست.">
          @csrf
          <input type="hidden" name="op_key" value="{{ \Illuminate\Support\Str::uuid() }}">
          <div class="form-grid">
            <div class="field"><label for="direction">نوع</label><select id="direction" name="direction" class="input"><option value="credit">شارژ (افزایش)</option><option value="debit">کسر (کاهش)</option></select></div>
            <div class="field"><label for="amount_toman">مبلغ (تومان)</label><input id="amount_toman" name="amount_toman" data-money inputmode="numeric" min="1" class="input ltr" required></div>
          </div>
          <button class="btn" type="submit">ثبت تراکنش</button>
          <p class="help muted">عملیات حساس: تأیید مجدد رمز می‌خواهد و با نام شما، زمان و شناسه تراکنش ثبت می‌شود.</p>
        </form>
      </div>
    @endcan
    @can('resellers.update')
      <div class="card" id="edit">
        <h2>اطلاعات و تخفیف سراسری</h2>
        <form method="post" action="{{ route('resellers.update', $r) }}">
          @csrf @method('put')
          <div class="form-grid">
            <div class="field"><label for="name">نام</label><input id="name" name="name" class="input" value="{{ old('name', $r->name) }}" required></div>
            <div class="field"><label for="phone">تلفن</label><input id="phone" name="phone" class="input ltr" value="{{ old('phone', $r->phone) }}"></div>
            <div class="field"><label for="telegram">تلگرام</label><input id="telegram" name="telegram" class="input ltr" value="{{ old('telegram', $r->telegram) }}"></div>
            <div class="field"><label for="gdp">تخفیف سراسری (٪)</label><input id="gdp" name="global_discount_percent" type="number" step="0.01" min="0" max="100" class="input ltr" value="{{ old('global_discount_percent', (float) $r->global_discount_percent) }}" required></div>
            <div class="field"><label for="status">وضعیت</label><select id="status" name="status" class="input"><option value="active" @selected($r->status === 'active')>فعال</option><option value="suspended" @selected($r->status === 'suspended')>تعلیق</option></select></div>
          </div>
          <div class="field"><label for="notes">یادداشت داخلی</label><input id="notes" name="notes" class="input" value="{{ old('notes', $r->notes) }}" maxlength="500"></div>
          <button class="btn btn-secondary" type="submit">ذخیره</button>
        </form>
      </div>
    @endcan
  </div>

  <div class="card">
    <h2>قیمت خرید این همکار</h2>
    <p class="muted">ترتیب: قیمت اختصاصی ← تخفیف دسته‌ای همکار ← تخفیف سراسری همکار ← قیمت عمومی. فقط یکی اعمال می‌شود و هرگز بیشتر از قیمت عمومی نیست.</p>
    @if($packages->isEmpty())<div class="empty"><div>بسته فعالی وجود ندارد.</div></div>@else
    <div class="table-wrap"><table class="table stack">
      <thead><tr><th>بسته</th><th>قیمت عمومی پایه</th><th>قیمت همکار</th><th>منشأ قیمت</th>@can('resellers.update')<th>قیمت اختصاصی (تومان)</th>@endcan</tr></thead>
      <tbody>
      @foreach($packages as $p)
        @php($q = $quotes[$p->id])
        <tr>
          <td data-label="بسته">{{ $p->name }} <span class="muted small">{{ $p->typeLabel() }}</span></td>
          <td data-label="قیمت پایه">{{ fa_money((int) $p->price_rial) }}</td>
          <td data-label="قیمت همکار"><strong>{{ fa_money($q->finalPrice) }}</strong></td>
          <td data-label="منشأ">{{ ['public' => 'قیمت عمومی', 'public_lower_than_reseller_rule' => 'قیمت عمومی (کمتر از قاعده همکار)', 'reseller_fixed' => 'قیمت اختصاصی', 'reseller_category' => 'تخفیف دسته‌ای همکار', 'reseller_global' => 'تخفیف سراسری همکار'][$q->priceSource] ?? $q->priceSource }}</td>
          @can('resellers.update')
            <td data-label="اختصاصی">
              <form method="post" action="{{ route('resellers.fixed-price', $r) }}" class="actions">@csrf
                <input type="hidden" name="package_id" value="{{ $p->id }}">
                @php($fx = $r->prices->firstWhere('package_id', $p->id))
                <input name="price_toman" data-money inputmode="numeric" min="0" class="input input-sm w-160 ltr" value="{{ $fx ? intdiv($fx->price_rial, 10) : '' }}" placeholder="خالی = ندارد" aria-label="قیمت اختصاصی">
                <button class="btn btn-secondary btn-sm" type="submit">ثبت</button>
              </form>
            </td>
          @endcan
        </tr>
      @endforeach
      </tbody>
    </table></div>
    @endif
    @can('resellers.update')
      <h3>تخفیف دسته‌ای</h3>
      <div class="actions mb-12">@forelse($r->categoryDiscounts as $cd)<span class="badge">{{ $cd->category?->name }}: {{ fa((string) (float) $cd->percent) }}٪</span>@empty<span class="muted">ندارد</span>@endforelse</div>
      @if($categories->isNotEmpty())
        <form method="post" action="{{ route('resellers.category-discount', $r) }}" class="actions">@csrf
          <select name="category_id" class="input w-160" aria-label="دسته">@foreach($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select>
          <input name="percent" type="number" step="0.01" min="0" max="100" class="input w-160 ltr" placeholder="درصد (۰ = حذف)" required aria-label="درصد">
          <button class="btn btn-secondary" type="submit">ثبت تخفیف دسته‌ای</button>
        </form>
      @endif
    @endcan
  </div>

  <div class="card">
    <h2>فروش‌ها</h2>
    @if($sales->isEmpty())<div class="empty"><div>هنوز فروشی ثبت نشده است.</div></div>@else
    <div class="table-wrap"><table class="table stack">
      <thead><tr><th>#</th><th>زمان</th><th>بسته</th><th>اکانت</th><th>خرید همکار</th><th>فروش به مشتری</th><th>سود</th><th>وضعیت</th></tr></thead>
      <tbody>
      @foreach($sales as $s)
        <tr>
          <td data-label="#">{{ fa((string) $s->id) }}</td>
          <td data-label="زمان">{{ jdate($s->created_at) }}</td>
          <td data-label="بسته">{{ $s->package?->name }}</td>
          <td data-label="اکانت">@if($s->account)<a href="{{ route('accounts.show', $s->account) }}"><bdi class="ltr">{{ $s->account->username }}</bdi></a>@else — @endif</td>
          <td data-label="خرید">{{ fa_money($s->cost_rial) }}</td>
          <td data-label="فروش">{{ fa_money($s->sale_price_rial) }}</td>
          <td data-label="سود">{{ fa_money($s->profit_rial) }}</td>
          <td data-label="وضعیت">@php([$l, $t] = \App\Models\ResellerSale::STATUS[$s->status])<span class="badge badge-{{ $t }}">{{ $l }}</span>
            @if($s->status === 'needs_review')
              @can('wallets.sensitive')
                <form method="post" action="{{ route('resellers.refund-sale', [$r, $s]) }}" class="actions" data-confirm="پس از بررسی سرور، مبلغ این فروش به همکار برگردد؟">@csrf
                  <button class="btn btn-danger btn-sm" type="submit">بازپرداخت</button></form>
              @endcan
            @endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
    {{ $sales->links() }}
    @endif
  </div>

  @if($ledger)
    <div class="card">
      <h2>دفتر کل کیف پول</h2>
      @if($ledger->isEmpty())<div class="empty"><div>هنوز تراکنشی ثبت نشده است.</div></div>@else
      <div class="table-wrap"><table class="table stack">
        <thead><tr><th>شناسه</th><th>زمان</th><th>نوع</th><th>مبلغ</th><th>موجودی پس از</th><th>شرح / دلیل</th><th>توسط</th></tr></thead>
        <tbody>
        @foreach($ledger as $e)
          <tr>
            <td data-label="شناسه">{{ fa((string) $e->id) }}</td>
            <td data-label="زمان">{{ jdate($e->created_at, 'Y/m/d H:i:s') }}</td>
            <td data-label="نوع">{{ $e->typeLabel() }}</td>
            <td data-label="مبلغ" @class(['text-danger' => $e->amount < 0])>{{ $e->amount < 0 ? '−' : '+' }}{{ fa_money(abs($e->amount)) }}</td>
            <td data-label="موجودی پس از">{{ fa_money($e->balance_after) }}</td>
            <td data-label="شرح">{{ $e->description }} @if($e->reason)<div class="muted small">{{ $e->reason }}</div>@endif</td>
            <td data-label="توسط">{{ $e->actor?->name ?? 'سیستم' }}
              @can('wallets.sensitive')
                @if(in_array($e->type, ['manual_credit', 'manual_debit', 'topup'], true))
                  <form method="post" action="{{ route('resellers.reverse', [$r, $e]) }}" class="actions" data-confirm="یک ردیف معکوس برای اصلاح این تراکنش ثبت شود؟">@csrf
                    <button class="btn btn-secondary btn-sm" type="submit">معکوس</button></form>
                @endif
              @endcan
            </td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
      {{ $ledger->links() }}
      @endif
    </div>
  @endif
</x-layouts.app>
