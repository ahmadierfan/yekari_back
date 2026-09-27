<?php

namespace Database\Seeders;

use App\Domain\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** idempotent — روی دیتابیس موجود هم بی‌خطر اجرا می‌شود و دسترسی تازه را اضافه می‌کند */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $p) {
            Permission::findOrCreate($p, 'web');
        }
        foreach (['customer', 'courier'] as $r) {
            Role::findOrCreate($r, 'web');
        }
        foreach (Permissions::ROLES as $name => $def) {
            $role = Role::findOrCreate($name, 'web');
            // فقط بار اول دسترسی پیش‌فرض بگیرد؛ بعد از آن ماتریس پنل حاکم است
            if ($role->wasRecentlyCreated && $name !== 'super-admin') {
                $role->syncPermissions(array_merge(Permissions::forGroups($def['groups']), $def['extra'] ?? []));
            }
        }
    }
}
