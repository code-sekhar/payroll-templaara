<?php

namespace App\Actions;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

class CreateCompanyForOwner
{
    public function handle(User $owner, string $companyName): Company
    {
        return DB::transaction(function () use ($owner, $companyName): Company {
            $registrar = app(PermissionRegistrar::class);
            $previousTeamId = $registrar->getPermissionsTeamId();
            $slug = Str::slug($companyName);
            $baseSlug = $slug;
            $suffix = 1;

            while (Company::where('slug', $slug)->exists()) {
                $slug = $baseSlug.'-'.$suffix++;
            }

            $company = Company::create(['name' => $companyName, 'slug' => $slug]);
            CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $owner->id,
                'status' => 'active',
            ]);

            try {
                $registrar->setPermissionsTeamId($company->id);
                app(SeedCompanyRoles::class)->handle($company);
                $owner->assignRole('company_owner');
            } finally {
                $registrar->setPermissionsTeamId($previousTeamId);
            }

            return $company;
        });
    }
}
