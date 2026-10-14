<?php

namespace App\Http\Controllers;

use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class CompanyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Company::class);
        $user = $request->user();
        $companies = $user->isSuperAdmin()
            ? Company::query()->orderBy('name')->get()
            : $user->companiesWithPermission('company.view')->sortBy('name')->values();

        return Inertia::render('companies/index', ['companies' => $companies]);
    }

    public function show(Company $company, ActiveCompany $activeCompany): Response
    {
        Gate::authorize('view', $company);

        return Inertia::render('companies/show', [
            'company' => $company,
            'can' => [
                'updateCompany' => auth()->user()->can('company.update'),
                'viewUsers' => auth()->user()->can('user.view'),
                'viewRoles' => auth()->user()->can('role.view'),
            ],
            'isActive' => $activeCompany->current()?->is($company) ?? false,
        ]);
    }

    public function update(UpdateCompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->validated());

        return to_route('companies.show', $company);
    }

    public function members(Company $company): Response
    {
        Gate::authorize('view', $company);
        Gate::authorize('user.view');

        $members = $company->companyUsers()->with('user:id,name,email')
            ->where('status', 'active')->orderBy('id')->get()
            ->map(fn ($membership) => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $membership->user->roles()->where('roles.team_id', $company->id)->value('name'),
            ]);

        return Inertia::render('companies/members', [
            'company' => $company,
            'members' => $members,
            'canManageUsers' => auth()->user()->can('user.assign_role'),
            'canAddUsers' => auth()->user()->can('user.create') && auth()->user()->can('user.assign_role'),
        ]);
    }

    public function roles(Company $company): Response
    {
        Gate::authorize('view', $company);
        Gate::authorize('role.view');

        $roles = Role::query()->where('team_id', $company->id)
            ->with('permissions:id,name')->orderBy('name')->get(['id', 'name']);

        return Inertia::render('companies/roles', ['company' => $company, 'roles' => $roles]);
    }
}
