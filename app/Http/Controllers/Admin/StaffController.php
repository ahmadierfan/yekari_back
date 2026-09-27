<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Domain;
use App\Domain\Permissions;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * کارمندان پنل و ماتریس نقش/دسترسی. بر خلاف پروتوتایپ، این ماتریس واقعاً اعمال
 * می‌شود: هر مسیر پنل پشت `permission:*` است.
 */
class StaffController extends Controller
{
    public function index()
    {
        return response()->json(['data' => User::role(User::STAFF_ROLES)->with('roles')->get()->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'phone' => $u->mobile, 'email' => $u->email,
            'role' => $u->roles->whereIn('name', User::STAFF_ROLES)->first()?->name,
            'lastSeen' => $u->last_seen_at?->toIso8601String(), 'status' => $u->status,
        ])]);
    }

    public function store(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:80', 'mobile' => 'required|string', 'email' => 'nullable|email', 'role' => ['required', Rule::in(User::STAFF_ROLES)]]);
        $mobile = Domain::normalizeMobile($d['mobile']);
        if (! Domain::isValidMobile($mobile)) {
            throw new ApiException('شمارهٔ موبایل معتبر نیست', 422, ['mobile' => ['نامعتبر']]);
        }
        $this->guardSuper($r, $d['role']);
        $u = User::firstOrCreate(['mobile' => $mobile], ['name' => $d['name'], 'email' => ! empty($d['email']) ? strtolower($d['email']) : null]);
        $u->syncRoles(array_merge($u->roles->pluck('name')->diff(User::STAFF_ROLES)->all(), [$d['role']]));
        Audit::log('create', "کارمند {$u->name} با نقش «".Permissions::ROLES[$d['role']]['title'].'» اضافه شد', $u);

        return response()->json(['id' => $u->id], 201);
    }

    public function setRole(Request $r, User $user)
    {
        $d = $r->validate(['role' => ['nullable', Rule::in(User::STAFF_ROLES)]]);
        abort_if($user->id === $r->user()->id, 422, 'نقش خودت را نمی‌توانی عوض کنی');
        $this->guardSuper($r, $d['role'] ?? null);
        if ($user->hasRole('super-admin')) {
            $this->guardSuper($r, 'super-admin');
        }
        $user->syncRoles(array_merge($user->roles->pluck('name')->diff(User::STAFF_ROLES)->all(), array_filter([$d['role'] ?? null])));
        if (! $d['role']) {
            $user->tokens()->get()->filter(fn ($t) => in_array('app:admin', $t->abilities))->each->delete();
        }
        Audit::log('update', "نقش {$user->name} به «".($d['role'] ? Permissions::ROLES[$d['role']]['title'] : 'بدون دسترسی').'» تغییر کرد', $user);

        return $this->index();
    }

    /** فقط مدیر ارشد می‌تواند مدیر ارشد بسازد یا بردارد */
    private function guardSuper(Request $r, ?string $role): void
    {
        if ($role === 'super-admin' && ! $r->user()->hasRole('super-admin')) {
            throw ApiException::forbidden('فقط مدیر ارشد می‌تواند این نقش را بدهد');
        }
    }

    public function roles()
    {
        return response()->json([
            'groups' => array_map(fn ($ps) => $ps, Permissions::GROUPS),
            'roles' => Role::whereIn('name', User::STAFF_ROLES)->with('permissions')->get()->map(fn (Role $role) => [
                'key' => $role->name,
                'title' => Permissions::ROLES[$role->name]['title'] ?? $role->name,
                'perms' => $role->name === 'super-admin' ? array_keys(Permissions::GROUPS) : Permissions::groupsOf($role->permissions->pluck('name')->all()),
                'permissions' => $role->name === 'super-admin' ? Permissions::all() : $role->permissions->pluck('name'),
                'locked' => $role->name === 'super-admin',
            ]),
        ]);
    }

    /** ویرایش ماتریس: یا گروهی (`groups`) یا ریز (`permissions`) */
    public function updateRole(Request $r, string $role)
    {
        abort_if($role === 'super-admin', 422, 'نقش مدیر ارشد قابل ویرایش نیست');
        $d = $r->validate([
            'groups' => 'array', 'groups.*' => Rule::in(array_keys(Permissions::GROUPS)),
            'permissions' => 'array', 'permissions.*' => Rule::in(Permissions::all()),
        ]);
        $roleModel = Role::findByName($role, 'web');
        $perms = isset($d['permissions']) ? $d['permissions'] : Permissions::forGroups($d['groups'] ?? []);
        $roleModel->syncPermissions($perms);
        Audit::log('update', 'دسترسی‌های نقش «'.(Permissions::ROLES[$role]['title'] ?? $role).'» تغییر کرد', null, ['permissions' => $perms]);

        return $this->roles();
    }
}
