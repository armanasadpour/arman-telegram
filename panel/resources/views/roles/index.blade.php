<x-layouts.app title="نقش‌ها و دسترسی‌ها">
  <div class="page-head">
    <h1>نقش‌ها و دسترسی‌ها</h1>
    @can('roles.create')<a class="btn" href="{{ route('roles.create') }}">نقش جدید</a>@endcan
  </div>
  <div class="card">
    <div class="table-wrap">
      <table class="table stack">
        <thead><tr><th>عنوان</th><th>شناسه</th><th>کاربران</th><th>مجوزها</th><th></th></tr></thead>
        <tbody>
        @foreach($roles as $role)
          <tr>
            <td data-label="عنوان">{{ $role->title }} @if($role->is_system)<span class="badge badge-primary">سیستمی</span>@endif
              @if($role->description)<div class="small muted">{{ $role->description }}</div>@endif</td>
            <td data-label="شناسه"><bdi class="ltr">{{ $role->name }}</bdi></td>
            <td data-label="کاربران">{{ fa($role->users_count) }}</td>
            <td data-label="مجوزها">{{ $role->isSuper() ? 'همه' : fa($role->permissions_count) }}</td>
            <td>
              @unless($role->is_system)
                <div class="actions">
                  @can('roles.update')<a class="btn btn-secondary btn-sm" href="{{ route('roles.edit', $role) }}">ویرایش</a>@endcan
                  @can('roles.delete')
                    @if($role->users_count === 0)
                      <form method="post" action="{{ route('roles.destroy', $role) }}" data-confirm="نقش «{{ $role->title }}» حذف شود؟" class="actions">
                        @csrf @method('delete')
                        <input name="reason" class="w-160 input-sm input" placeholder="دلیل حذف" required minlength="3" aria-label="دلیل حذف">
                        <button class="btn btn-danger btn-sm" type="submit">حذف</button>
                      </form>
                    @endif
                  @endcan
                </div>
              @endunless
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
  </div>
</x-layouts.app>
