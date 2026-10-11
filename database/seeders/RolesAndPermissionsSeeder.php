<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Rôles communs à toutes les boutiques (store_id null) ; leur attribution, elle, se fait par boutique.
 * Idempotent : à relancer après chaque modification des enums Role ou Permission.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();
        $previousTeam = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        foreach (PermissionEnum::cases() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::query()->whereNull('store_id')->where('name', $roleEnum->value)->where('guard_name', 'web')->first()
                ?? Role::create(['name' => $roleEnum->value, 'guard_name' => 'web']);
            $role->syncPermissions(array_map(fn (PermissionEnum $p) => $p->value, $roleEnum->permissions()));
        }

        $registrar->setPermissionsTeamId($previousTeam);
        $registrar->forgetCachedPermissions();
    }
}
