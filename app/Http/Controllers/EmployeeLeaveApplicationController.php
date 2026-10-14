<?php

namespace App\Http\Controllers;

use App\Actions\LeaveRequests\CreateLeaveRequest;
use App\Exceptions\Holidays\InvalidHolidayCalendarConfigurationException;
use App\Exceptions\Leave\LeaveRequestConfigurationException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Http\Requests\LeaveRequest\StoreEmployeeLeaveApplicationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Employees\EmployeeLeaveOverviewQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeLeaveApplicationController extends Controller
{
    public function create(Request $request, ActiveCompany $activeCompany, EmployeeLeaveOverviewQuery $overviewQuery): Response
    {
        [$employee, $company] = $this->portalContext($request, $activeCompany);
        Gate::authorize('createSelf', [LeaveRequest::class, $company, $employee]);
        $overview = $overviewQuery->forEmployee($employee, $company);

        return Inertia::render('employee-portal/leave-apply', [
            'asOfDate' => $overview->props['asOfDate'],
            'leaveTypes' => $overview->applicationTypes,
            'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            'companyName' => $company->name,
            'employeeName' => $employee->full_name,
        ]);
    }

    public function store(StoreEmployeeLeaveApplicationRequest $request, ActiveCompany $activeCompany, CreateLeaveRequest $action): RedirectResponse
    {
        [$employee, $company] = $this->portalContext($request, $activeCompany);
        $data = $request->validated();
        $leaveType = LeaveType::query()->where('company_id', $company->getKey())
            ->whereKey($data['leave_type_id'])->where('status', 'active')->firstOrFail();

        try {
            $action->handle(
                $employee,
                $leaveType,
                $data['start_date'],
                $data['end_date'],
                $data['duration'],
                $data['reason'] ?? null,
                null,
                $request->user(),
            );
        } catch (LeaveRequestConfigurationException $exception) {
            throw ValidationException::withMessages(['start_date' => $exception->getMessage()]);
        } catch (NoWorkScheduleAssignmentException|InvalidWorkScheduleConfigurationException|InvalidHolidayCalendarConfigurationException) {
            throw ValidationException::withMessages([
                'start_date' => 'Leave could not be calculated for these dates. Please contact HR to check your schedule and holiday setup.',
            ]);
        }

        return redirect()->route('employee.leave.index')->with('success', 'Leave request submitted. It is now pending review.');
    }

    /** @return array{Employee, Company} */
    private function portalContext(Request $request, ActiveCompany $activeCompany): array
    {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);
        abort_unless($request->user() instanceof User, 403);

        return [$employee, $company];
    }
}
