<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? 'ورود' }} — پنل فروش</title>
@if(is_file(public_path('fonts/IRANSans-400.woff2')))<link rel="stylesheet" href="{{ asset('assets/fonts.css') }}?v={{ config('panel.version') }}">@endif
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('panel.version') }}">
<link rel="manifest" href="{{ route('pwa.manifest') }}" data-sw="{{ asset('sw.js') }}">
@php($pwaV = app(\App\Services\Settings::class)->get('pwa.icon_version', '0'))
<meta name="theme-color" content="{{ app(\App\Services\Settings::class)->get('pwa.theme') ?? '#4f46e5' }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ app(\App\Services\Settings::class)->get('pwa.short_name') ?? 'پنل' }}">
<link rel="apple-touch-icon" href="{{ url('/pwa/icon-180.png') }}?v={{ $pwaV }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ url('/pwa/icon-192.png') }}?v={{ $pwaV }}">
</head>
<body>
<main class="guest">
  <div class="card">
    @include('partials.flash')
    {{ $slot }}
  </div>
</main>
<script src="{{ asset('assets/app.js') }}?v={{ config('panel.version') }}"></script>
</body>
</html>
