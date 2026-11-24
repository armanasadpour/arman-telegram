<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>دسترسی ندارید</title>
@if(is_file(public_path('fonts/IRANSans-400.woff2')))<link rel="stylesheet" href="{{ asset('assets/fonts.css') }}">@endif
<link rel="stylesheet" href="{{ asset('assets/app.css') }}"></head>
<body><main class="guest"><div class="center card">
<h1>دسترسی ندارید</h1>
<p class="muted">@if(true && isset($exception) && $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.'){{ $exception->getMessage() }}@else شما اجازه دسترسی به این بخش را ندارید. @endif</p>
<a class="btn" href="{{ url('/') }}">بازگشت به پنل</a>
</div></main></body></html>
