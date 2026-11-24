@php($editing = $role->exists)
<x-layouts.app :title="$editing ? 'ویرایش نقش' : 'نقش جدید'">
  <div class="page-head">
    <h1>{{ $editing ? 'ویرایش نقش «'.$role->title.'»' : 'نقش جدید' }}</h1>
    <a class="btn btn-secondary" href="{{ route('roles.index') }}">بازگشت</a>
  </div>
  <form method="post" action="{{ $editing ? route('roles.update', $role) : route('roles.store') }}">
    @csrf
    @if($editing) @method('put') @endif
    <div class="card">
      <div class="form-grid">
        <div class="field">
          <label for="title">عنوان نقش</label>
          <input id="title" name="title" class="input @error('title') is-invalid @enderror" value="{{ old('title', $role->title) }}" required maxlength="120" placeholder="مثلاً: اپراتور فروش">
        </div>
        <div class="field">
          <label for="name">شناسه انگلیسی</label>
          <input id="name" name="name" class="input ltr @error('name') is-invalid @enderror" value="{{ old('name', $role->name) }}" required maxlength="60" placeholder="sales_operator">
          <div class="help">حروف کوچک انگلیسی، عدد و زیرخط؛ با حرف شروع شود.</div>
        </div>
      </div>
      <div class="field">
        <label for="description">توضیح (اختیاری)</label>
        <input id="description" name="description" class="input" value="{{ old('description', $role->description) }}" maxlength="500">
      </div>
    </div>

    <div class="card">
      <h2>مجوزها</h2>
      @php($checked = array_map('intval', old('permissions', $selected)))
      <div class="table-wrap">
        <table class="table perm-table">
          <thead><tr><th>بخش</th>@foreach($matrix['actions'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
          <tbody>
          @foreach($matrix['rows'] as $row)
            <tr>
              <td>{{ $row['title'] }}</td>
              @foreach($row['cells'] as $action => $pid)
                <td>
                  @if($pid)
                    <input class="cb" type="checkbox" name="permissions[]" value="{{ $pid }}" @checked(in_array($pid, $checked)) aria-label="{{ $matrix['actions'][$action] }} {{ $row['title'] }}">
                  @else
                    <span class="muted" aria-hidden="true">—</span>
                  @endif
                </td>
              @endforeach
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      <p class="help muted">«عملیات حساس» شامل غیرفعال‌سازی کاربر، تعیین رمز برای دیگران و تنظیمات محرمانه است و همیشه تأیید مجدد رمز و ثبت دلیل می‌خواهد.</p>
    </div>
    <button type="submit" class="btn">{{ $editing ? 'ذخیره نقش' : 'ایجاد نقش' }}</button>
  </form>
</x-layouts.app>
