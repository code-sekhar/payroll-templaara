<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AddCompanyMember
{
    public function handle(Company $company, User $user, string $roleName): CompanyUser
    {
        return DB::transaction(function () use ($company, $user, $roleName): CompanyUser {
            $registrar = app(PermissionRegistrar::class);
            $previousTeamId = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($company->id);

            try {
                $membership = CompanyUser::create([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'status' => 'active',
                ]);

                $role = Role::query()->where('team_id', $company->id)
                    ->where('name', $roleName)->where('guard_name', 'web')->firstOrFail();

                $user->assignRole($role);

                return $membership;
            } finally {
                $registrar->setPermissionsTeamId($previousTeamId);
            }
        });
    }
}
