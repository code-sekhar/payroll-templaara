<?php

namespace App\Http\Controllers;

use App\Actions\AttendanceRegularizations\CancelAttendanceRegularizationRequest;
use App\Actions\AttendanceRegularizations\CreateAttendanceRegularizationRequest as CreateRegularization;
use App\Http\Requests\EmployeeAttendanceCorrectionCreateRequest;
use App\Http\Requests\EmployeeAttendanceCorrectionStoreRequest;
use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\AttendanceRegularizationNotificationService;
use App\Services\Attendance\EmployeeAttendanceCorrectionContextQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeAttendanceCorrectionController extends Controller
{
    public function create(
        EmployeeAttendanceCorrectionCreateRequest $request,
        ActiveCompany $activeCompany,
        EmployeeAttendanceCorrectionContextQuery $contextQuery,
    ): Response {
        [$company, $employee] = $this->portalContext($request, $activeCompany);
        $date = $request->validated('attendance_date');

        return Inertia::render('employee-portal/attendance-correction-create', [
            'context' => $contextQuery->forDate($employee, $company, $date),
            'employeePortal' => [
                'companyName' => $company->name,
                'employeeName' => $employee->full_name,
                'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            ],
        ]);
    }

    public function store(
        EmployeeAttendanceCorrectionStoreRequest $request,
        ActiveCompany $activeCompany,
        EmployeeAttendanceCorrectionContextQuery $contextQuery,
        CreateRegularization $create,
        AttendanceRegularizationNotificationService $notifications,
    ): RedirectResponse {
        [$company, $employee] = $this->portalContext($request, $activeCompany);
        $data = $request->validated();
        $data['items'] = $contextQuery->resolveSubmittedItems($employee, $company, $data['attendance_date'], $data['items']);
        $regularization = $create->handle($company, $employee, $request->user(), $data, selfRequest: true);
        $notifications->correctionSubmitted($regularization);

        return to_route('employee.attendance.index', ['month' => substr($data['attendance_date'], 0, 7)])
            ->with('success', 'Your attendance correction request was submitted. Your attendance has not changed.');
    }

    public function cancel(
        Request $request,
        string $attendanceDate,
        string $requestReference,
        ActiveCompany $activeCompany,
        CancelAttendanceRegularizationRequest $cancel,
    ): RedirectResponse {
        [$company, $employee] = $this->portalContext($request, $activeCompany);
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $requestReference) === 1, 404);
        $regularization = RegularizationRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereDate('attendance_date', $attendanceDate)
            ->where('status', 'pending')
            ->first();
        abort_unless($regularization instanceof RegularizationRequest
            && hash_equals(
                hash_hmac('sha256', implode('|', [$company->getKey(), $employee->getKey(), $regularization->getKey()]), (string) config('app.key')),
                $requestReference,
            ), 404);

        Gate::authorize('cancel', $regularization);
        $cancelled = $cancel->handle($regularization);

        return to_route('employee.attendance.index', ['month' => $cancelled->attendance_date->format('Y-m')])
            ->with('success', 'Your attendance correction request was cancelled.');
    }

    /** @return array{Company, Employee} */
    private function portalContext(Request $request, ActiveCompany $activeCompany): array
    {
        $company = $activeCompany->current();
        $employee = $request->attributes->get('employee_portal.employee');
        abort_unless($company instanceof Company && $employee instanceof Employee, 403);

        return [$company, $employee];
    }
}
