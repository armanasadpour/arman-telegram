@php($siteName = app(\App\Services\Settings::class)->get('general.site_name'))
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? 'پنل' }} — {{ $siteName }}</title>
@if(is_file(public_path('fonts/IRANSans-400.woff2')))<link rel="stylesheet" href="{{ asset('assets/fonts.css') }}?v={{ config('panel.version') }}">@endif
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('panel.version') }}">
</head>
<body>
<div class="shell">
  <header class="header">
    <button id="menu-btn" class="menu-btn" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="منو"><x-icon name="menu" width="20" height="20"/></button>
    <div class="brand">{{ $siteName }}</div>
    <div class="user">
      <a href="{{ route('profile.password') }}" title="تغییر رمز">{{ auth()->user()->name }}</a>
      <form method="post" action="{{ route('logout') }}">
        @csrf
        <button class="btn btn-secondary btn-sm" type="submit" title="خروج"><x-icon name="logout" width="16" height="16"/><span>خروج</span></button>
      </form>
    </div>
  </header>

  <nav id="sidebar" class="sidebar nav" aria-label="منوی اصلی">
    @if(auth()->user()->isReseller())
      <a href="{{ route('portal.dashboard') }}" @class(['active' => request()->routeIs('portal.dashboard')])><x-icon name="home"/>پیشخوان</a>
      @if(auth()->user()->reseller->isActive())<a href="{{ route('portal.sell') }}" @class(['active' => request()->routeIs('portal.sell*') && ! request()->query('account')])><x-icon name="box"/>فروش اکانت جدید</a>@endif
      <a href="{{ route('portal.accounts') }}" @class(['active' => request()->routeIs('portal.accounts*')])><x-icon name="user"/>اکانت‌های من</a>
      <a href="{{ route('portal.sales') }}" @class(['active' => request()->routeIs('portal.sales*')])><x-icon name="list"/>فروش‌ها</a>
      <a href="{{ route('portal.ledger') }}" @class(['active' => request()->routeIs('portal.ledger')])><x-icon name="key"/>کیف پول</a>
      <a href="{{ route('portal.prices') }}" @class(['active' => request()->routeIs('portal.prices')])><x-icon name="tag"/>قیمت‌های من</a>
    @endif
    @can('dashboard.view')
      <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])><x-icon name="home"/>داشبورد</a>
    @endcan
    @if(auth()->user()->can('accounts.view') || auth()->user()->can('ibs_groups.view'))
      <div class="section">سرور</div>
      @can('accounts.view')
        <a href="{{ route('accounts.index') }}" @class(['active' => request()->routeIs('accounts.*')])><x-icon name="user"/>اکانت‌ها</a>
      @endcan
      @can('ibs_groups.view')
        <a href="{{ route('ibs.groups') }}" @class(['active' => request()->routeIs('ibs.groups')])><x-icon name="layers"/>گروه‌ها و تعرفه‌ها</a>
      @endcan
    @endif
    @if(auth()->user()->can('packages.view') || auth()->user()->can('discounts.view') || auth()->user()->can('resellers.view'))
      <div class="section">فروش</div>
      @can('resellers.view')
        <a href="{{ route('resellers.index') }}" @class(['active' => request()->routeIs('resellers.*')])><x-icon name="users"/>همکاران فروش</a>
      @endcan
      @can('packages.view')
        <a href="{{ route('packages.index') }}" @class(['active' => request()->routeIs('packages.*')])><x-icon name="box"/>بسته‌ها</a>
      @endcan
      @can('discounts.view')
        <a href="{{ route('discounts.index') }}" @class(['active' => request()->routeIs('discounts.*')])><x-icon name="tag"/>تخفیف‌ها</a>
      @endcan
    @endif
    @if(auth()->user()->can('staff.view') || auth()->user()->can('roles.view'))
      <div class="section">مدیریت دسترسی</div>
      @can('staff.view')
        <a href="{{ route('staff.index') }}" @class(['active' => request()->routeIs('staff.*')])><x-icon name="users"/>کاربران پنل</a>
      @endcan
      @can('roles.view')
        <a href="{{ route('roles.index') }}" @class(['active' => request()->routeIs('roles.*')])><x-icon name="shield"/>نقش‌ها و دسترسی‌ها</a>
      @endcan
    @endif
    @if(auth()->user()->can('audit.view') || auth()->user()->can('settings.view') || auth()->user()->can('system.view'))
      <div class="section">سیستم</div>
      @can('audit.view')
        <a href="{{ route('audit.index') }}" @class(['active' => request()->routeIs('audit.*')])><x-icon name="list"/>گزارش رویدادها</a>
      @endcan
      @can('settings.view')
        <a href="{{ route('settings.edit') }}" @class(['active' => request()->routeIs('settings.*')])><x-icon name="settings"/>تنظیمات</a>
        <a href="{{ route('ibs.settings') }}" @class(['active' => request()->routeIs('ibs.settings*')])><x-icon name="link"/>اتصال به سرور IBSng</a>
      @endcan
      @can('system.view')
        <a href="{{ route('system.status') }}" @class(['active' => request()->routeIs('system.*')])><x-icon name="activity"/>وضعیت سرویس‌ها</a>
      @endcan
    @endif
    <div class="section">حساب من</div>
    <a href="{{ route('profile.password') }}" @class(['active' => request()->routeIs('profile.*')])><x-icon name="key"/>تغییر رمز عبور</a>
  </nav>
  <div id="backdrop" class="backdrop"></div>

  <main class="main">
    @if(auth()->user()->isSuperAdmin() && \App\Services\SystemStatus::pendingMigrations() > 0)
      <div class="alert alert-warning">نسخه جدید نصب شده و دیتابیس باید به‌روزرسانی شود. <a href="{{ route('system.status') }}">اجرای به‌روزرسانی</a></div>
    @endif
    @include('partials.flash')
    {{ $slot }}
  </main>
</div>
<script src="{{ asset('assets/app.js') }}?v={{ config('panel.version') }}"></script>
</body>
</html>
