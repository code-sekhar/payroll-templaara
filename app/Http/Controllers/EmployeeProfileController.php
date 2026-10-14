<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Employees\EmployeeProfileQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeProfileController extends Controller
{
    public function show(Request $request, ActiveCompany $activeCompany, EmployeeProfileQuery $profileQuery): Response
    {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);

        return Inertia::render('employee-portal/profile', [
            'employeeProfile' => $profileQuery->forEmployee($employee, $company)->props,
            'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            'companyName' => $company->name,
        ]);
    }
}
