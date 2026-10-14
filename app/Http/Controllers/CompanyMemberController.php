<?php

namespace App\Http\Controllers;

use App\Actions\AddCompanyMember;
use App\Http\Requests\Company\StoreCompanyUserRequest;
use App\Http\Requests\Company\UpdateCompanyUserRoleRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Spatie\Permission\Models\Role;

class CompanyMemberController extends Controller
{
    public function store(StoreCompanyUserRequest $request, Company $company, AddCompanyMember $addCompanyMember): RedirectResponse
    {
        $validated = $request->validated();
        $user = User::query()->where('email', $validated['email'])->firstOrFail();

        $addCompanyMember->handle($company, $user, $validated['role']);

        return to_route('companies.members', $company);
    }

    public function update(UpdateCompanyUserRoleRequest $request, Company $company, User $user): RedirectResponse
    {
        abort_unless($company->companyUsers()->where('user_id', $user->id)->where('status', 'active')->exists(), 404);
        abort_if($user->hasRole('company_owner'), 403);

        $role = Role::query()->where('team_id', $company->id)
            ->where('name', $request->validated('role'))->where('guard_name', 'web')->firstOrFail();

        $user->syncRoles([$role]);

        return to_route('companies.members', $company);
    }
}
