<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('roles.view');
        $roles = Role::query()->withCount(['users', 'permissions'])->orderByDesc('is_system')->orderBy('title')->get();

        return view('roles.index', compact('roles'));
    }

    public function create(): View
    {
        $this->authorize('roles.create');

        return view('roles.form', ['role' => new Role, 'selected' => [], 'matrix' => $this->matrix()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('roles.create');
        $data = $request->validate($this->rules(null));
        $permIds = $this->permissionIds($request);

        $role = DB::transaction(function () use ($data, $permIds) {
            $role = Role::query()->create($data);
            $role->permissions()->sync($permIds);
            $this->audit->log('roles.create', $role, 'ایجاد نقش «'.$role->title.'»', [
                'title' => [null, $role->title],
                'permissions' => [[], $this->permissionNames($permIds)],
            ]);

            return $role;
        });

        return redirect()->route('roles.index')->with('success', 'نقش «'.$role->title.'» ایجاد شد.');
    }

    public function edit(Role $role): View
    {
        $this->authorize('roles.update');
        $this->guardSystem($role);

        return view('roles.form', [
            'role' => $role,
            'selected' => $role->permissions()->pluck('permissions.id')->all(),
            'matrix' => $this->matrix(),
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.update');
        $this->guardSystem($role);
        $data = $request->validate($this->rules($role));
        $permIds = $this->permissionIds($request);

        DB::transaction(function () use ($role, $data, $permIds) {
            $before = ['name' => $role->name, 'title' => $role->title, 'description' => $role->description,
                'permissions' => $role->permissions()->orderBy('name')->pluck('name')->all()];
            $role->fill($data)->save();
            $role->permissions()->sync($permIds);
            $diff = AuditLogger::diff($before, $data + ['permissions' => $this->permissionNames($permIds)]);
            if ($diff) {
                $this->audit->log('roles.update', $role, 'ویرایش نقش «'.$role->title.'»', $diff);
            }
        });

        return redirect()->route('roles.index')->with('success', 'نقش ذخیره شد.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('roles.delete');
        $this->guardSystem($role);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($role->users()->exists()) {
            return back()->with('error', 'این نقش به کاربرانی اختصاص دارد. ابتدا نقش را از آن‌ها بردارید.');
        }
        $this->audit->log('roles.delete', $role, 'حذف نقش «'.$role->title.'»', [
            'permissions' => [$role->permissions()->pluck('name')->all(), []],
        ], $data['reason']);
        $role->delete();

        return redirect()->route('roles.index')->with('success', 'نقش حذف شد.');
    }

    // ----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function rules(?Role $role): array
    {
        return [
            'name' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')->ignore($role?->id),
                Rule::notIn([config('permissions.super_role')])],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<int> */
    private function permissionIds(Request $request): array
    {
        $request->validate(['permissions' => ['array'], 'permissions.*' => ['integer', 'exists:permissions,id']]);

        return array_values(array_unique(array_map('intval', (array) $request->input('permissions', []))));
    }

    /** @param list<int> $ids @return list<string> */
    private function permissionNames(array $ids): array
    {
        return Permission::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }

    private function guardSystem(Role $role): void
    {
        if ($role->is_system) {
            abort(403, 'نقش‌های سیستمی قابل ویرایش یا حذف نیستند.');
        }
    }

    /**
     * Rows = modules, columns = actions; cell = permission id or null when not applicable.
     *
     * @return array{actions: array<string,string>, rows: list<array{module:string,title:string,cells:array<string,?int>}>}
     */
    private function matrix(): array
    {
        $perms = Permission::query()->get()->keyBy('name');
        $rows = [];
        foreach (config('permissions.modules') as $module => $def) {
            $cells = [];
            foreach (array_keys(config('permissions.actions')) as $action) {
                $cells[$action] = $perms["$module.$action"]->id ?? null;
            }
            $rows[] = ['module' => $module, 'title' => $def['title'], 'cells' => $cells];
        }

        return ['actions' => config('permissions.actions'), 'rows' => $rows];
    }
}
