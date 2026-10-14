<?php

namespace App\Tenancy;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class ActiveCompany
{
    private ?Company $company = null;

    public function resolve(Request $request): ?Company
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        $routeCompany = $request->route('company');

        if ($routeCompany instanceof Company) {
            abort_unless(
                $user->isSuperAdmin() || ($routeCompany->status === 'active'
                    && $user->companies()->whereKey($routeCompany->getKey())->wherePivot('status', 'active')->exists()),
                404,
            );

            $this->company = $routeCompany;
            $request->session()->put('active_company_id', $routeCompany->getKey());
        } else {
            $companyId = $request->session()->get('active_company_id');

            if ($user->isSuperAdmin()) {
                $this->company = $companyId
                    ? Company::query()->whereKey($companyId)->where('status', 'active')->first()
                    : null;

                if ($companyId && ! $this->company) {
                    $request->session()->forget('active_company_id');
                }
            } else {
                $membership = $user->companyUsers()->where('status', 'active')
                    ->whereHas('company', fn ($query) => $query->where('status', 'active'));

                if ($companyId) {
                    $selected = (clone $membership)->where('company_id', $companyId)->with('company')->first();
                    $this->company = $selected?->company;

                    if (! $this->company) {
                        $request->session()->forget('active_company_id');
                    }
                }

                if (! $this->company && (clone $membership)->count() === 1) {
                    $this->company = $membership->with('company')->first()?->company;
                    $request->session()->put('active_company_id', $this->company?->getKey());
                }
            }
        }

        $registrar->setPermissionsTeamId($this->company?->getKey());
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $this->company;
    }

    public function current(): ?Company
    {
        return $this->company;
    }
}
