<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeAttendanceIndexRequest;
use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\EmployeeAttendanceCorrectionHistoryQuery;
use App\Services\Attendance\EmployeeAttendanceMonthQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeAttendanceController extends Controller
{
    public function index(
        EmployeeAttendanceIndexRequest $request,
        ActiveCompany $activeCompany,
        EmployeeAttendanceMonthQuery $attendance,
        EmployeeAttendanceCorrectionHistoryQuery $correctionHistory,
    ): Response {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);

        $user = $request->user();
        $canRequestCorrection = Gate::allows('createSelf', [RegularizationRequest::class, $company, $employee]);
        $canViewCorrections = $user->can('attendance_regularization.self_view');
        $monthData = $attendance->forEmployee($employee, $company, $request->validated('month'), $canViewCorrections);

        return Inertia::render('employee-portal/attendance', [
            'employeeAttendance' => $monthData->toArray(),
            'canRequestCorrection' => $canRequestCorrection,
            'canViewCorrections' => $canViewCorrections,
            'correctionRequests' => $canViewCorrections
                ? $correctionHistory->forMonth($employee, $company, $monthData->month, $user)
                : [],
            'employeePortal' => [
                'companyName' => $company->name,
                'employeeName' => $employee->full_name,
                'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            ],
        ]);
    }
}
