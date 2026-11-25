<x-layouts.app title="اتصال به ربات">
  <div class="page-head"><h1>اتصال به ربات</h1></div>
  <div class="card">
    @if($bots->isEmpty())
      <div class="empty"><div>هنوز رباتی فعال نشده است.</div></div>
    @else
      <p>با اتصال حساب پنل به ربات، {{ auth()->user()->isReseller() ? 'از داخل پیام‌رسان اکانت بفروشید، تمدید کنید، موجودی کیف پول را ببینید و نتیجه فروش‌ها را دریافت کنید.' : 'اعلان‌های مدیریتی (فروش نیازمند رسیدگی و …) و وضعیت سیستم را در پیام‌رسان دریافت کنید.' }}</p>
      <ol>
        <li>ربات را در پیام‌رسان باز کنید:
          @foreach($bots as $b)
            <span class="badge">{{ $b->label() }}@if($b->bot_username) <bdi class="ltr">{{ '@'.$b->bot_username }}</bdi>@endif</span>
          @endforeach
        </li>
        <li>دکمه «ورود همکاران و مدیران» را بزنید و کد زیر را بفرستید.</li>
      </ol>
      @if($code)
        <div class="alert alert-success">کد اتصال: <strong><bdi class="ltr">{{ $code }}</bdi></strong> — فقط یک بار و تا ۱۵ دقیقه معتبر است.</div>
      @endif
      <form method="post" action="{{ route('bot-link.create') }}">@csrf<button class="btn" type="submit">ساخت کد اتصال</button></form>
    @endif
  </div>
  <div class="card">
    <h2>گفتگوهای وصل‌شده</h2>
    @forelse($linked as $l)
      <div class="actions mb-8">
        <span>{{ $l->bot?->label() }} — {{ $l->first_name ?? '—' }} @if($l->handle)<bdi class="ltr">{{ '@'.$l->handle }}</bdi>@endif — {{ $l->last_seen_at ? fa_ago($l->last_seen_at) : '' }}</span>
        <form method="post" action="{{ route('bot-link.unlink', $l) }}" class="inline" data-confirm="اتصال این گفتگو قطع شود؟">@csrf @method('DELETE')<button class="btn btn-secondary btn-sm" type="submit">قطع اتصال</button></form>
      </div>
    @empty
      <p class="muted">هیچ گفتگویی وصل نیست.</p>
    @endforelse
  </div>
</x-layouts.app>
