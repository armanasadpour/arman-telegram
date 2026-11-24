<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $title ?? 'ورود' }} — {{ app(\App\Services\Settings::class)->get('general.site_name') }}</title>
@if(is_file(public_path('fonts/IRANSansWeb.woff2')))<link rel="stylesheet" href="{{ asset('assets/fonts.css') }}?v={{ config('panel.version') }}">@endif
<link rel="stylesheet" href="{{ asset('assets/app.css') }}?v={{ config('panel.version') }}">
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
