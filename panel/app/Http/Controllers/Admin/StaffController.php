<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $this->authorize('staff.view');
        $q = trim((string) $request->query('q', ''));
        $users = User::query()
            ->with('roles')
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('username', 'like', "%$q%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('staff.index', compact('users', 'q'));
    }

    public function create(): View
    {
        $this->authorize('staff.create');

        return view('staff.form', ['user' => new User(['is_active' => true]), 'roles' => $this->assignableRoles()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('staff.create');
        $data = $request->validate($this->rules(null) + [
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-zA-Z0-9_.-]+$/', 'unique:users,username'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);
        $roleIds = $this->validatedRoleIds($request);

        $user = DB::transaction(function () use ($data, $roleIds) {
            $user = User::query()->create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'mobile' => $data['mobile'] ?? null,
                'password' => $data['password'],
                'is_active' => true,
            ]);
            $user->forceFill(['password_changed_at' => now()])->save();
            $user->roles()->sync($roleIds);
            $this->audit->log('staff.create', $user, 'ایجاد کاربر پنل «'.$user->username.'»', [
                'name' => [null, $user->name],
                'roles' => [[], $this->roleNames($roleIds)],
            ]);

            return $user;
        });

        return redirect()->route('staff.index')->with('success', 'کاربر «'.$user->username.'» ایجاد شد.');
    }

    public function edit(User $staff): View
    {
        $this->authorize('staff.update');
        $this->guardSuperTarget($staff);

        return view('staff.form', ['user' => $staff->load('roles'), 'roles' => $this->assignableRoles()]);
    }

    public function update(Request $request, User $staff): RedirectResponse
    {
        $this->authorize('staff.update');
        $this->guardSuperTarget($staff);
        $data = $request->validate($this->rules($staff));
        $roleIds = $this->validatedRoleIds($request);
        $this->guardLastSuperAdmin($staff, $roleIds);

        DB::transaction(function () use ($staff, $data, $roleIds) {
            $before = ['name' => $staff->name, 'email' => $staff->email, 'mobile' => $staff->mobile, 'roles' => $staff->roles->pluck('name')->sort()->values()->all()];
            $staff->fill(['name' => $data['name'], 'email' => $data['email'] ?? null, 'mobile' => $data['mobile'] ?? null])->save();
            $staff->roles()->sync($roleIds);
            $after = ['name' => $staff->name, 'email' => $staff->email, 'mobile' => $staff->mobile, 'roles' => $this->roleNames($roleIds)];
            $diff = AuditLogger::diff($before, $after);
            if ($diff) {
                $this->audit->log('staff.update', $staff, 'ویرایش کاربر «'.$staff->username.'»', $diff);
            }
        });

        return redirect()->route('staff.index')->with('success', 'تغییرات ذخیره شد.');
    }

    /** Sensitive: activate / deactivate. Requires recent password confirmation and a reason. */
    public function toggleActive(Request $request, User $staff): RedirectResponse
    {
        $this->authorize('staff.sensitive');
        $this->guardSuperTarget($staff);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($staff->is($request->user())) {
            return back()->with('error', 'نمی‌توانید حساب خودتان را غیرفعال کنید.');
        }
        if ($staff->is_active) {
            $this->guardLastSuperAdmin($staff, [], deactivating: true);
        }

        $staff->is_active = ! $staff->is_active;
        $staff->save();
        if (! $staff->is_active) {
            DB::table('sessions')->where('user_id', $staff->id)->delete();
        }
        $this->audit->log($staff->is_active ? 'staff.activate' : 'staff.deactivate', $staff,
            ($staff->is_active ? 'فعال‌سازی' : 'غیرفعال‌سازی').' «'.$staff->username.'»',
            ['is_active' => [! $staff->is_active, $staff->is_active]], $data['reason']);

        return back()->with('success', $staff->is_active ? 'حساب فعال شد.' : 'حساب غیرفعال شد و از همه نشست‌ها خارج شد.');
    }

    /** Sensitive: set a new password for another user. */
    public function resetPassword(Request $request, User $staff): RedirectResponse
    {
        $this->authorize('staff.sensitive');
        $this->guardSuperTarget($staff);
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $staff->password = $data['password'];
        $staff->password_changed_at = now();
        $staff->save();
        DB::table('sessions')->where('user_id', $staff->id)->delete();
        $this->audit->log('staff.password_reset', $staff, 'تعیین رمز جدید برای «'.$staff->username.'»', null, $data['reason']);

        return back()->with('success', 'رمز جدید ثبت شد و کاربر از همه نشست‌ها خارج شد.');
    }

    public function destroy(Request $request, User $staff): RedirectResponse
    {
        $this->authorize('staff.delete');
        $this->guardSuperTarget($staff);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        if ($staff->is($request->user())) {
            return back()->with('error', 'نمی‌توانید حساب خودتان را حذف کنید.');
        }
        $this->guardLastSuperAdmin($staff, [], deactivating: true);

        DB::transaction(function () use ($staff, $data) {
            $this->audit->log('staff.delete', $staff, 'حذف کاربر «'.$staff->username.'»', [
                'username' => [$staff->username, null],
                'roles' => [$staff->roles->pluck('name')->all(), []],
            ], $data['reason']);
            DB::table('sessions')->where('user_id', $staff->id)->delete();
            $staff->delete();
        });

        return redirect()->route('staff.index')->with('success', 'کاربر حذف شد.');
    }

    // ---------------------------------------------------------------- rules & guards

    /** @return array<string, mixed> */
    private function rules(?User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'mobile' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s]+$/'],
            'roles' => ['array'],
            'roles.*' => ['integer', 'exists:roles,id'],
        ];
    }

    /** @return list<int> */
    private function validatedRoleIds(Request $request): array
    {
        $ids = array_map('intval', (array) $request->input('roles', []));
        $allowed = $this->assignableRoles()->pluck('id')->all();
        foreach ($ids as $id) {
            if (! in_array($id, $allowed, true)) {
                abort(403, 'شما اجازه اختصاص این نقش را ندارید.');
            }
        }

        return array_values(array_unique($ids));
    }

    /** Only a super admin may grant the super-admin role. */
    private function assignableRoles()
    {
        return Role::query()
            ->where('name', '!=', config('permissions.reseller_role'))
            ->when(! request()->user()->isSuperAdmin(), fn ($q) => $q->where('name', '!=', config('permissions.super_role')))
            ->orderByDesc('is_system')->orderBy('title')->get();
    }

    /** Non-super admins cannot touch super-admin accounts. */
    private function guardSuperTarget(User $target): void
    {
        if ($target->isSuperAdmin() && ! request()->user()->isSuperAdmin()) {
            abort(403, 'فقط مدیرکل می‌تواند حساب مدیرکل را تغییر دهد.');
        }
    }

    /** @param list<int> $newRoleIds */
    private function guardLastSuperAdmin(User $target, array $newRoleIds, bool $deactivating = false): void
    {
        $superId = Role::query()->where('name', config('permissions.super_role'))->value('id');
        $losesSuper = $target->isSuperAdmin() && ($deactivating || ! in_array($superId, $newRoleIds, true));
        if (! $losesSuper) {
            return;
        }
        $activeSupers = User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('roles.id', $superId))->count();
        if ($activeSupers <= 1) {
            abort(422, 'حداقل یک مدیرکل فعال باید باقی بماند.');
        }
    }

    /** @param list<int> $ids @return list<string> */
    private function roleNames(array $ids): array
    {
        return Role::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->all();
    }
}
