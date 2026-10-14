<?php

namespace App\Http\Controllers;

use App\Actions\LeaveRequests\CancelLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Http\Requests\EmployeeLeaveRequestHistoryRequest;
use App\Http\Requests\EmployeeLeaveWithdrawalRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Employees\EmployeeLeaveRequestDetailQuery;
use App\Services\Employees\EmployeeLeaveRequestHistoryQuery;
use App\Tenancy\ActiveCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeLeaveRequestController extends Controller
{
    public function index(
        EmployeeLeaveRequestHistoryRequest $request,
        ActiveCompany $activeCompany,
        EmployeeLeaveRequestHistoryQuery $historyQuery,
    ): Response {
        [$employee, $company] = $this->portalContext($request, $activeCompany);
        $data = $historyQuery->forEmployee($employee, $company, $request->validated());

        return Inertia::render('employee-portal/leave-requests/index', [
            'history' => [
                'requests' => $data->requests,
                'leaveTypes' => $data->leaveTypes,
                'filters' => $data->filters,
                'pagination' => $data->pagination,
            ],
            ...$this->portalProps($request, $employee, $company),
        ]);
    }

    public function show(
        Request $request,
        ActiveCompany $activeCompany,
        EmployeeLeaveRequestDetailQuery $detailQuery,
        int $requestId,
    ): Response {
        [$employee, $company] = $this->portalContext($request, $activeCompany);
        $ownedRequest = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereKey($requestId)
            ->firstOrFail();
        $detail = $detailQuery->findForEmployee($employee, $company, $requestId);

        abort_if($detail === null, 404);
        $canWithdraw = (bool) ($request->user()?->can('leave_request.self_cancel')
            && Gate::allows('cancel', $ownedRequest));

        return Inertia::render('employee-portal/leave-requests/show', [
            'leaveRequest' => $detail->toArray(),
            'canWithdraw' => $canWithdraw,
            'withdrawUrl' => $canWithdraw ? route('employee.leave.requests.cancel', $requestId) : null,
            ...$this->portalProps($request, $employee, $company),
        ]);
    }

    public function cancel(
        EmployeeLeaveWithdrawalRequest $request,
        ActiveCompany $activeCompany,
        CancelLeaveRequest $action,
        int $requestId,
    ): RedirectResponse {
        [$employee, $company] = $this->portalContext($request, $activeCompany);
        $ownedRequest = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $employee->getKey())
            ->whereKey($requestId)
            ->firstOrFail();
        $detailUrl = route('employee.leave.requests.show', $requestId);

        if ($ownedRequest->status !== LeaveRequestStatus::Pending) {
            return to_route('employee.leave.requests.show', $requestId)
                ->with('warning', 'This request is no longer pending. Its current status is shown here.');
        }

        Gate::authorize('cancel', $ownedRequest);

        try {
            $action->handle($ownedRequest, $request->user(), $request->validated('cancellation_reason'));
        } catch (ValidationException) {
            return redirect($detailUrl)
                ->with('warning', 'This request changed while you were withdrawing it. Refresh the page to see its current status.');
        }

        return to_route('employee.leave.requests.show', $requestId)
            ->with('success', 'Your pending leave request was withdrawn.');
    }

    /** @return array{Employee, Company} */
    private function portalContext(Request $request, ActiveCompany $activeCompany): array
    {
        $employee = $request->attributes->get('employee_portal.employee');
        $company = $activeCompany->current();

        abort_unless($employee instanceof Employee && $company instanceof Company, 403);
        abort_unless((int) $employee->company_id === (int) $company->getKey(), 403);
        abort_unless($request->user() instanceof User, 403);
        abort_unless($request->user()->can('leave_request.self_view'), 403);

        return [$employee, $company];
    }

    /** @return array{canAccessManagement: bool, canApplyForLeave: bool, companyName: string, employeeName: string} */
    private function portalProps(Request $request, Employee $employee, Company $company): array
    {
        return [
            'canAccessManagement' => (bool) $request->attributes->get('employee_portal.can_access_management', false),
            'canApplyForLeave' => (bool) $request->user()?->can('createSelf', [LeaveRequest::class, $company, $employee]),
            'companyName' => $company->name,
            'employeeName' => $employee->full_name,
        ];
    }
}
