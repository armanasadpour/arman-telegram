<x-layouts.app title="فایل‌های کانفیگ OpenVPN">
  <div class="page-head">
    <div>
      <h1>فایل‌های کانفیگ OpenVPN</h1>
      <p class="muted">فایل ‎.ovpn را یک بار اینجا بگذارید؛ هر وقت خواستید دانلود کنید یا لینک مستقیمش را برای مشتری بفرستید. نام فایل، همان نام پروفایل در برنامه OpenVPN Connect می‌شود.</p>
    </div>
  </div>

  @if($files->isEmpty())
    <div class="card mb-16"><div class="empty"><x-icon name="box"/><div>هنوز فایلی اضافه نشده است.</div></div></div>
  @endif

  @foreach($files as $f)
    <section class="card mb-16 cfg-file">
      <header class="cfg-head">
        <div>
          <h2 class="ltr"><bdi>{{ $f->fileName() }}</bdi></h2>
          <p class="muted small">{{ $f->sizeLabel() }} · دانلود با لینک: {{ fa($f->downloads) }} بار @if($f->last_download_at)· آخرین: {{ jdate($f->last_download_at) }}@endif @if($f->creator)· افزوده‌شده توسط {{ $f->creator->name }}@endif</p>
        </div>
        <a class="btn btn-sm" href="{{ route('config-files.download', $f) }}">دانلود</a>
      </header>
      <div class="cfg-link">
        <input class="input ltr" id="cfl{{ $f->id }}" value="{{ $f->publicUrl() }}" readonly onclick="this.select()">
        <button type="button" class="btn btn-secondary" data-copy="#cfl{{ $f->id }}">کپی لینک</button>
      </div>
      <p class="muted small mt-8">هر کس این لینک را داشته باشد فایل را دانلود می‌کند؛ اگر لینک دست افراد ناخواسته افتاد، «لینک جدید» بزنید.</p>
      @can('ibs_groups.update')
        <details class="mt-8">
          <summary class="btn btn-secondary btn-sm">ویرایش / جایگزینی فایل</summary>
          <form method="post" action="{{ route('config-files.update', $f) }}" enctype="multipart/form-data" class="mt-8">
            @csrf @method('put')
            <div class="field"><label>نام (نام پروفایل و نام فایل)</label><input class="input ltr" name="name" value="{{ $f->name }}" required maxlength="64"></div>
            <div class="field"><label>فایل جدید (اختیاری)</label><input class="input" type="file" name="file" accept=".ovpn,.conf,text/plain,application/x-openvpn-profile"><div class="help">اگر خالی بماند همان فایل قبلی می‌ماند. لینک مستقیم عوض نمی‌شود.</div></div>
            <button class="btn btn-sm" type="submit">ذخیره</button>
          </form>
          <div class="actions mt-8">
            <form method="post" action="{{ route('config-files.new-link', $f) }}" data-confirm="لینک جدید ساخته شود؟ لینک فعلی دیگر کار نمی‌کند.">@csrf<button class="btn btn-secondary btn-sm" type="submit">لینک جدید</button></form>
            <form method="post" action="{{ route('config-files.destroy', $f) }}" data-confirm="فایل «{{ $f->name }}» حذف شود؟ لینک آن هم از کار می‌افتد.">@csrf @method('delete')<button class="btn btn-danger btn-sm" type="submit">حذف</button></form>
          </div>
        </details>
      @endcan
    </section>
  @endforeach

  @can('ibs_groups.update')
    <section class="card w-640">
      <h2>افزودن فایل کانفیگ</h2>
      <form method="post" action="{{ route('config-files.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="field">
          <label for="cf_name">نام</label>
          <input id="cf_name" class="input ltr" name="name" value="{{ old('name') }}" placeholder="Server11" required maxlength="64">
          <div class="help">با حروف انگلیسی. فایل با نام <span class="ltr">Server11.ovpn</span> دانلود می‌شود و در برنامه هم با همین نام نشان داده می‌شود.</div>
        </div>
        <div class="field">
          <label for="cf_file">فایل ‎.ovpn</label>
          <input id="cf_file" class="input" type="file" name="file" accept=".ovpn,.conf,text/plain,application/x-openvpn-profile">
        </div>
        <details class="mb-8"><summary class="muted small">یا متن فایل را بچسبانید</summary>
          <textarea class="input ltr mt-8" name="content" rows="6" dir="ltr">{{ old('content') }}</textarea>
        </details>
        <button class="btn" type="submit">ذخیره</button>
      </form>
    </section>
  @endcan
</x-layouts.app>
