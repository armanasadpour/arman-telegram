{{-- Username + password fields with generate / show helpers. $prefix: id prefix, $withOld: refill old() values --}}
<div class="field">
  <label for="{{ $prefix }}username">نام کاربری (اختیاری)</label>
  <div class="input-group">
    <span class="addon addon-icon"><x-icon name="user"/></span>
    <input id="{{ $prefix }}username" name="username" class="input ltr @if($withOld) @error('username') is-invalid @enderror @endif" value="{{ $withOld ? old('username') : '' }}" maxlength="32" autocapitalize="none" spellcheck="false" placeholder="خودکار" autocomplete="off">
  </div>
  @if($withOld) @error('username')<div class="error">{{ $message }}</div>@enderror @endif
</div>
<div class="field">
  <label for="{{ $prefix }}password">رمز (اختیاری)</label>
  <div class="input-group">
    <span class="addon addon-icon"><x-icon name="key"/></span>
    <input id="{{ $prefix }}password" name="password" class="input ltr" value="{{ $withOld ? old('password') : '' }}" maxlength="64" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="تصادفی">
    <button type="button" class="addon addon-btn" data-gen-pass="#{{ $prefix }}password" title="ساخت رمز تصادفی">🎲 تصادفی</button>
  </div>
  <div class="help">هر رمزی بخواهید؛ خالی = رمز تصادفی امن.</div>
</div>
