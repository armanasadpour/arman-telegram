@php([$label, $secret] = \App\Http\Controllers\Admin\PaymentSettingsController::FIELDS[$k])
@php($name = str_replace('.', '__', $k))
<div class="field">
  <label for="{{ $name }}">{{ $label }}</label>
  @if($secret)
    <input class="input ltr" id="{{ $name }}" name="{{ $name }}" type="password" autocomplete="new-password" placeholder="{{ $values[$k] ?? 'تنظیم نشده' }}">
  @else
    <input class="input ltr" id="{{ $name }}" name="{{ $name }}" type="{{ str_ends_with($name, '_toman') ? 'text' : ($type ?? 'text') }}" @if(str_ends_with($name, '_toman')) data-money inputmode="numeric" @endif autocomplete="off" value="{{ old($name, $values[$k] ?? '') }}">
  @endif
</div>
