<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>عملیات انجام نشد</title>
<link rel="stylesheet" href="{{ asset('assets/app.css') }}"></head>
<body><main class="guest"><div class="center card">
<h1>عملیات انجام نشد</h1>
<p class="muted">@if(true && isset($exception) && $exception->getMessage() && $exception->getMessage() !== 'This action is unauthorized.'){{ $exception->getMessage() }}@else درخواست قابل انجام نیست. @endif</p>
<a class="btn" href="{{ url('/') }}">بازگشت به پنل</a>
</div></main></body></html>
