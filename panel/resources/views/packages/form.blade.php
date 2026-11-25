@php($editing = $p->exists)
<x-layouts.app :title="$editing ? 'ویرایش بسته' : 'بسته جدید'">
  <div class="page-head">
    <h1>{{ $editing ? 'ویرایش بسته «'.$p->name.'»' : 'بسته جدید' }}</h1>
    <a class="btn btn-secondary" href="{{ route('packages.index') }}">بازگشت</a>
  </div>
  @if($groups->isEmpty())
    <div class="alert alert-warning">هیچ گروهی از سرور همگام نشده است؛ برای بسته «اکانت جدید» ابتدا <a href="{{ route('ibs.groups') }}">گروه‌ها را همگام‌سازی کنید</a>.</div>
  @endif
  <div class="card w-640">
    <form method="post" action="{{ $editing ? route('packages.update', $p) : route('packages.store') }}">
      @csrf @if($editing) @method('put') @endif
      <div class="form-grid">
        <div class="field"><label for="name">نام بسته</label><input id="name" name="name" class="input" value="{{ old('name', $p->name) }}" required maxlength="120" placeholder="مثلاً یک‌ماهه ۲۰ گیگ"></div>
        <div class="field"><label for="type">نوع</label>
          <select id="type" name="type" class="input">
            @foreach(\App\Models\Package::TYPES as $k => $v)<option value="{{ $k }}" @selected(old('type', $p->type) === $k)>{{ $v }}</option>@endforeach
          </select>
          <div class="help">اکانت جدید: ساخت اکانت. تمدید: افزودن روز (و در صورت تعریف حجم). افزایش حجم: فقط حجم.</div>
        </div>
        <div class="field"><label for="protocol">پروتکل</label>
          <select id="protocol" name="protocol" class="input">
            @foreach(\App\Models\Package::PROTOCOLS as $k => $v)<option value="{{ $k }}" @selected(old('protocol', $p->protocol ?? 'openvpn') === $k)>{{ $v }}</option>@endforeach
          </select>
          <div class="help">بسته تمدید/حجم میکروتیک روی همه اکانت‌های L2TP و WireGuard قابل اعمال است.</div>
        </div>
        <div class="field"><label for="category_id">دسته (اختیاری)</label>
          <select id="category_id" name="category_id" class="input"><option value="">بدون دسته</option>
            @foreach($categories as $c)<option value="{{ $c->id }}" @selected((int) old('category_id', $p->category_id) === $c->id)>{{ $c->name }}</option>@endforeach
          </select></div>
        <div class="field"><label for="mt_group_id">گروه میکروتیک (اکانت جدید L2TP/WireGuard)</label>
          <select id="mt_group_id" name="mt_group_id" class="input"><option value="">—</option>
            @foreach($mtGroups as $g)<option value="{{ $g->id }}" @selected((int) old('mt_group_id', $p->mt_group_id) === $g->id)>{{ $g->label() }}</option>@endforeach
          </select>@error('mt_group_id')<div class="error">{{ $message }}</div>@enderror
        </div>
        <div class="field"><label for="ibs_group_name">گروه سرور OpenVPN (فقط اکانت جدید)</label>
          <select id="ibs_group_name" name="ibs_group_name" class="input"><option value="">—</option>
            @foreach($groups as $g)<option value="{{ $g->name }}" @selected(old('ibs_group_name', $p->ibs_group_name) === $g->name)>{{ $g->name }} — {{ $g->charge?->isVolume() ? 'تعرفه حجمی' : 'بدون محدودیت حجم' }}</option>@endforeach
          </select>
          <div class="help">اگر تعرفه گروه حجمی باشد، بسته باید حجم داشته باشد و برعکس.</div>
        </div>
        <div class="field"><label for="duration_days">مدت اعتبار (روز)</label><input id="duration_days" name="duration_days" type="number" min="1" max="3650" class="input ltr" value="{{ old('duration_days', $p->duration_days) }}"><div class="help">برای اکانت جدید از اولین اتصال شمرده می‌شود.</div></div>
        <div class="field"><label for="volume_gb">حجم (گیگابایت)</label><input id="volume_gb" name="volume_gb" type="number" step="0.1" min="0.1" class="input ltr" value="{{ old('volume_gb', $p->volume_gb !== null ? (float) $p->volume_gb : '') }}"><div class="help">خالی = حجم نامحدود (برای گروه‌های بدون محدودیت حجم).</div></div>
@error('ibs_group_name')<div class="error">{{ $message }}</div>@enderror
        <div class="field"><label for="multi_login">تعداد اتصال همزمان (اختیاری)</label><input id="multi_login" name="multi_login" type="number" min="1" max="255" class="input ltr" value="{{ old('multi_login', $p->multi_login) }}"><div class="help">خالی = مقدار گروه سرور.</div></div>
        <div class="field"><label for="price_toman">قیمت (تومان)</label><input id="price_toman" name="price_toman" type="number" min="0" class="input ltr" value="{{ old('price_toman', $p->exists ? intdiv((int) $p->price_rial, 10) : '') }}" required></div>
        <div class="field"><label for="sort_order">ترتیب نمایش</label><input id="sort_order" name="sort_order" type="number" min="0" class="input ltr" value="{{ old('sort_order', $p->sort_order ?? 0) }}"></div>
      </div>
      <div class="field"><label for="description">توضیح (برای مشتری)</label><textarea id="description" name="description" class="input" maxlength="500">{{ old('description', $p->description) }}</textarea></div>
      <label class="check mb-16"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $p->is_active))> فعال (قابل فروش)</label>
      <button class="btn" type="submit">{{ $editing ? 'ذخیره بسته' : 'ایجاد بسته' }}</button>
    </form>
  </div>
  @if($editing)
    @can('packages.delete')
      <div class="card card-danger w-640">
        <h2 class="text-danger">حذف بسته</h2>
        <p class="muted">بسته از فروش خارج می‌شود؛ سوابق و Snapshotهای قبلی حفظ می‌شوند.</p>
        <form method="post" action="{{ route('packages.destroy', $p) }}" class="actions" data-confirm="بسته حذف شود؟">
          @csrf @method('delete')
          
          <button class="btn btn-danger" type="submit">حذف</button>
        </form>
      </div>
    @endcan
  @endif
</x-layouts.app>
