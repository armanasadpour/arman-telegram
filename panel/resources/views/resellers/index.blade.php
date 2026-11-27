<x-layouts.app title="همکاران فروش">
  <x-hero title="همکاران فروش" text="هر همکار پنل و کیف پول اختصاصی دارد و فقط داده‌های خودش را می‌بیند."
    :action="auth()->user()->can('resellers.create') ? 'همکار جدید' : null" :action-url="route('resellers.create')"/>
  @if($review)<div class="alert alert-warning">{{ fa($review) }} فروش همکار نیازمند رسیدگی است (وضعیت سرور نامشخص ماند).</div>@endif

  @if($resellers->isEmpty())
    <div class="card"><div class="empty"><x-icon name="users"/><div>هنوز همکاری ثبت نشده است.</div></div></div>
  @else
    <div class="rs-list">
      @foreach($resellers as $r)
        <article class="rs-card">
          <header class="rs-head">
            <span class="rs-avatar"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></svg></span>
            <div class="rs-who"><a href="{{ route('resellers.show', $r) }}"><strong>{{ $r->name }}</strong></a>
              @if($r->isActive())<span class="acc-pill tone-success"><i></i>همکار فعال</span>@else<span class="acc-pill tone-warning"><i></i>تعلیق</span>@endif</div>
            @can('resellers.update')<a class="rs-edit" href="{{ route('resellers.show', $r) }}#edit" title="ویرایش"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20l4.5-1 10-10a2.1 2.1 0 0 0-3-3l-10 10L4 20z"/><path d="M14 7l3 3"/></svg><span>ویرایش</span></a>@endcan
          </header>
          <a class="rs-rows" href="{{ route('resellers.show', $r) }}">
            <span class="rs-row"><span class="rs-ic"><svg viewBox="0 0 24 24"><rect x="3.5" y="5" width="17" height="14" rx="2.5"/><circle cx="9" cy="11" r="2"/><path d="M6 16c.6-1.6 1.7-2.4 3-2.4s2.4.8 3 2.4M14 10h4M14 13.5h3"/></svg></span><span class="rs-l">کد همکار</span><bdi class="rs-v ltr">{{ $r->code }}</bdi></span>
            <span class="rs-row"><span class="rs-ic"><svg viewBox="0 0 24 24"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H18v3"/><rect x="3.5" y="7.5" width="17" height="12" rx="2.5"/><path d="M16 13.5h2"/></svg></span><span class="rs-l">موجودی کیف پول</span><span class="rs-v">{{ fa_money($r->wallet?->balance ?? 0) }}</span></span>
            <span class="rs-row"><span class="rs-ic"><svg viewBox="0 0 24 24"><path d="M6 20v-5M11 20v-9M16 20V7M21 20V4"/></svg></span><span class="rs-l">فروش موفق</span><span class="rs-v">{{ fa($r->sales_ok) }}</span></span>
            <span class="rs-row"><span class="rs-ic"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="7" rx="7" ry="3"/><path d="M5 7v5c0 1.7 3.1 3 7 3s7-1.3 7-3V7M5 12v5c0 1.7 3.1 3 7 3s7-1.3 7-3v-5"/></svg></span><span class="rs-l">سود همکار</span><span class="rs-v">{{ fa_money((int) $r->profit) }}</span></span>
            <span class="rs-row"><span class="rs-ic"><svg viewBox="0 0 24 24"><path d="M3 12V4h8l10 10-8 8L3 12z"/><circle cx="7.5" cy="8.5" r="1.5"/></svg></span><span class="rs-l">تخفیف سراسری</span><span class="rs-v">{{ fa((string) (float) $r->global_discount_percent) }}٪</span></span>
            <span class="rs-row"><span class="rs-ic"><svg viewBox="0 0 24 24"><path d="M12 3v8M6.3 6.6a8 8 0 1 0 11.4 0"/></svg></span><span class="rs-l">وضعیت</span>@if($r->isActive())<span class="acc-pill tone-success"><i></i>فعال</span>@else<span class="acc-pill tone-warning"><i></i>تعلیق</span>@endif</span>
          </a>
        </article>
      @endforeach
    </div>
  @endif
</x-layouts.app>
