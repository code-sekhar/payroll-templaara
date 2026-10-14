<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\EmployeePunchStateQuery;
use App\Services\EmployeeDashboardQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeePortalController extends Controller
{
    public function index(
        Request $request,
        ActiveCompany $activeCompany,
        EmployeeDashboardQuery $dashboard,
        EmployeePunchStateQuery $punchState,
    ): Response {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);

        return Inertia::render('employee-portal/index', [
            'employeePortal' => [
                ...$dashboard->forEmployee($employee, $company)->props,
                'punchState' => $punchState->forEmployee($employee, $company)->toArray(),
                'employeeName' => $employee->full_name,
                'companyName' => $company->name,
                'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            ],
        ]);
    }
}
