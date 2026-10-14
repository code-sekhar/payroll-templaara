<?php

namespace Database\Seeders;

use App\Actions\SeedCompanyRoles;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId(null);

        foreach (SeedCompanyRoles::allPermissionNames() as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        $registrar->setPermissionsTeamId(User::SYSTEM_TEAM_ID);
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web', 'team_id' => User::SYSTEM_TEAM_ID]);
        $superAdmin->syncPermissions(Permission::where('guard_name', 'web')->get());

        foreach (Company::query()->orderBy('id')->get() as $company) {
            app(SeedCompanyRoles::class)->handle($company);
        }

        $registrar->setPermissionsTeamId($previousTeamId);
        $registrar->forgetCachedPermissions();
    }
}
