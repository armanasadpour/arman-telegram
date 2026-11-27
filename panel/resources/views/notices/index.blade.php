<x-layouts.app title="اعلان‌ها">
  <div class="list-head">
    <span class="list-head-ico notice-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9z"/><path d="M10 20a2 2 0 0 0 4 0"/></svg></span>
    <div><h1>اعلان‌ها</h1><p>قطع و وصل شدن سرورها و ارتباط پنل با سرور OpenVPN (هر ۵ تا ۱۰ دقیقه بررسی می‌شود) و درخواست‌های شارژ حساب همکاران.</p></div>
  </div>

  <div class="card mb-16 push-card">
    <div>
      <strong>اعلان روی گوشی و مرورگر</strong>
      <p class="muted small">وقتی پنل باز است (حتی در پس‌زمینه)، اعلان‌های جدید با صدا و پیام سیستم نشان داده می‌شوند. برای وقتی پنل بسته است، حساب خود را به <a href="{{ route('bot-link') }}">ربات</a> وصل کنید تا همین هشدارها در تلگرام/بله هم بیاید.</p>
    </div>
    <button type="button" class="btn btn-sm" data-enable-notify hidden>فعال‌سازی اعلان مرورگر</button>
    <span class="badge badge-success" data-notify-on hidden>اعلان مرورگر فعال است</span>
  </div>

  @if($open->isNotEmpty())
    <div class="card mb-16 open-problems">
      <h2>مشکلات فعلی</h2>
      @foreach($open as $n)
        <div class="notice-row lvl-danger">
          <span class="nd"></span>
          <div><strong>{{ $n->title }}</strong>@if($n->body)<div class="small muted ltr-auto">{{ $n->body }}</div>@endif
            <div class="small muted">از {{ jdate($n->created_at, 'Y/m/d H:i') }} ({{ fa_ago($n->created_at) }})</div></div>
          @if($n->url)<a class="btn btn-secondary btn-sm" href="{{ $n->url }}">بررسی</a>@endif
        </div>
      @endforeach
    </div>
  @endif

  <div class="card">
    <h2>همه اعلان‌ها</h2>
    @if($rows->isEmpty())
      <div class="empty"><x-icon name="inbox"/><div>تا حالا مشکلی ثبت نشده است.</div></div>
    @else
      @foreach($rows as $n)
        <div @class(['notice-row', 'lvl-'.$n->level, 'is-new' => ! $seen || $n->created_at->gt($seen)])>
          <span class="nd"></span>
          <div><strong>{{ $n->title }}</strong>
            @if($n->body)<div class="small muted">{{ $n->body }}</div>@endif
            <div class="small muted">{{ jdate($n->created_at, 'Y/m/d H:i') }}@if($n->level === 'danger') — {{ $n->resolved_at ? 'برطرف شد '.jdate($n->resolved_at, 'Y/m/d H:i').' (پس از '.fa_duration((int) $n->created_at->diffInSeconds($n->resolved_at)).')' : 'هنوز برقرار نشده' }}@endif</div>
          </div>
          @if($n->url)<a class="btn btn-secondary btn-sm" href="{{ $n->url }}">مشاهده</a>@endif
        </div>
      @endforeach
      {{ $rows->links() }}
    @endif
  </div>
</x-layouts.app>
