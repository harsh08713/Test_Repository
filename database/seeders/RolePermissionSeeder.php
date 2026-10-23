<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Shared\Services\PermissionRegistry;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every permission in the registry and the standard roles.
 *
 * Safe to re-run (e.g. after a new module adds forms):
 *  - missing permissions are added;
 *  - Super Admin is re-synced to hold *every* permission, so it never silently misses a new one and its
 *    reach is always explicit and visible (there is no implicit bypass in the code);
 *  - every other role only receives its default grants when it is first created, so a matrix an admin has
 *    customised in the UI is never overwritten.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionRegistry::allPermissionNames() as $name) {
            Permission::findOrCreate($name);
        }

        Role::findOrCreate(User::SUPER_ADMIN_ROLE)->syncPermissions(PermissionRegistry::allPermissionNames());

        foreach (PermissionRegistry::defaultRoles() as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName);

            if ($role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
