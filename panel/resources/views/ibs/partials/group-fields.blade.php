<div class="field">
  <label for="days">مدت اعتبار از اولین اتصال (روز)</label>
  <input class="input" type="number" id="days" name="days" min="1" max="3650" required value="{{ old('days', $g && $g->rel_exp_seconds ? intdiv($g->rel_exp_seconds, 86400) : 30) }}">
</div>
<div class="field">
  <label for="multi_login">تعداد اتصال همزمان</label>
  <input class="input" type="number" id="multi_login" name="multi_login" min="1" max="255" required value="{{ old('multi_login', $g->multi_login ?? 1) }}">
</div>
<div class="field">
  <label for="charge">تعرفه</label>
  <select class="input" id="charge" name="charge" required>
    @foreach($activeCharges as $c)
      <option value="{{ $c }}" @selected(old('charge', $g->charge_name ?? null) === $c)>{{ $c }}</option>
    @endforeach
  </select>
  <div class="help">برای بسته‌های حجمی، تعرفه‌ای را انتخاب کنید که در جدول تعرفه‌ها «حجمی» نوشته شده است.</div>
</div>
