<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Employees\EmployeeLeaveOverviewQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeLeaveController extends Controller
{
    public function index(Request $request, ActiveCompany $activeCompany, EmployeeLeaveOverviewQuery $overviewQuery): Response
    {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);
        abort_unless($request->user()?->can('leave_request.self_view'), 403);

        return Inertia::render('employee-portal/leave', [
            'employeeLeave' => $overviewQuery->forEmployee($employee, $company)->props,
            'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            'canApplyForLeave' => (bool) $request->user()?->can('createSelf', [LeaveRequest::class, $company, $employee]),
            'companyName' => $company->name,
            'employeeName' => $employee->full_name,
        ]);
    }
}
