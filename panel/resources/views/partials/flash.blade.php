@if (session('success'))
  <div class="alert alert-success" role="status">{{ session('success') }}</div>
@endif
@if (session('warning'))
  <div class="alert alert-warning" role="status">{{ session('warning') }}</div>
@endif
@if (session('error'))
  <div class="alert alert-danger" role="alert">{{ session('error') }}</div>
@endif
@if ($errors->any())
  <div class="alert alert-danger" role="alert">
    لطفاً خطاهای زیر را برطرف کنید:
    <ul>
      @foreach ($errors->all() as $e)
        <li>{{ $e }}</li>
      @endforeach
    </ul>
  </div>
@endif
