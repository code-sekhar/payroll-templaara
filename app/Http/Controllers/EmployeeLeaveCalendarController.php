<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeLeaveCalendarRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Employees\EmployeeLeaveCalendarQuery;
use App\Tenancy\ActiveCompany;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeLeaveCalendarController extends Controller
{
    public function __invoke(
        EmployeeLeaveCalendarRequest $request,
        ActiveCompany $activeCompany,
        EmployeeLeaveCalendarQuery $calendarQuery,
    ): Response {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);

        $calendar = $calendarQuery->forEmployee($employee, $company, $request->validated('month'));

        return Inertia::render('employee-portal/leave-calendar', [
            'calendar' => $calendar->toArray(),
            'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            'companyName' => $company->name,
            'employeeName' => $employee->full_name,
        ]);
    }
}
