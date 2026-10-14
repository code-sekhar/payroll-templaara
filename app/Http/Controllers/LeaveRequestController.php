<?php

namespace App\Http\Controllers;

use App\Actions\LeaveRequests\ApproveLeaveRequest;
use App\Actions\LeaveRequests\CancelApprovedLeaveAndSynchronizeAttendance;
use App\Actions\LeaveRequests\CancelLeaveRequest;
use App\Actions\LeaveRequests\CreateLeaveRequest;
use App\Actions\LeaveRequests\RejectLeaveRequest;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\Holidays\InvalidHolidayCalendarConfigurationException;
use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Exceptions\Leave\LeaveRequestConfigurationException;
use App\Exceptions\WorkSchedules\InvalidWorkScheduleConfigurationException;
use App\Exceptions\WorkSchedules\NoWorkScheduleAssignmentException;
use App\Http\Requests\LeaveRequest\ApproveLeaveRequestRequest;
use App\Http\Requests\LeaveRequest\CancelApprovedLeaveHttpRequest;
use App\Http\Requests\LeaveRequest\CancelLeaveRequestRequest;
use App\Http\Requests\LeaveRequest\RejectLeaveRequestRequest;
use App\Http\Requests\LeaveRequest\StoreLeaveRequestRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Tenancy\ActiveCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    public function index(Request $request, Company $company, LeaveRequestReviewerScope $reviewerScope): Response
    {
        $this->assertActiveCompany($company);
        Gate::authorize('viewAny', [LeaveRequest::class, $company]);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:pending,approved,rejected,cancelled'],
            'leave_type_id' => ['nullable', 'integer'], 'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'], 'department_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'],
        ]);
        $query = $reviewerScope->apply(LeaveRequest::query(), $request->user(), $company)->with([
            'employee:id,company_id,employee_code,first_name,middle_name,last_name,department_id,branch_id',
            'leaveType:id,name,code', 'requester:id,name', 'reviewedBy:id,name',
        ]);
        $this->applyFilters($query, $validated, $company);
        $requests = $query->orderByDesc('requested_at')->orderByDesc('id')->paginate(20)->withQueryString()->through(fn (LeaveRequest $item): array => [
            'id' => $item->id,
            'employee' => ['name' => $item->employee->full_name, 'code' => $item->employee->employee_code],
            'leave_type' => ['id' => $item->leaveType->id, 'name' => $item->leaveType->name],
            'start_date' => $item->start_date->toDateString(), 'end_date' => $item->end_date->toDateString(),
            'requested_days' => (string) $item->requested_days, 'status' => $item->status->value,
            'requested_at' => $item->requested_at?->toDateTimeString(),
            'reviewer' => $item->reviewedBy?->name,
        ]);

        return Inertia::render('leave-requests/index', [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'requests' => $requests,
            'filters' => $validated,
            'leaveTypes' => $company->leaveTypes()->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']),
            'branches' => Branch::query()->where('company_id', $company->id)->orderBy('name')->get(['id', 'name']),
            'canCreate' => $request->user()->can('createForCompany', [LeaveRequest::class, $company]),
        ]);
    }

    public function create(Request $request, Company $company): Response
    {
        $this->assertActiveCompany($company);
        Gate::authorize('createForCompany', [LeaveRequest::class, $company]);

        return Inertia::render('leave-requests/create', [
            'employees' => $company->employees()->orderBy('employee_code')->get(['id', 'employee_code', 'first_name', 'middle_name', 'last_name']),
            'showEmployeeSelector' => true,
            'leaveTypes' => $company->leaveTypes()->where('status', 'active')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'allows_half_day']),
            'storeUrl' => route('companies.leave-requests.store', $company),
            'backUrl' => route('companies.leave-requests.index', $company),
        ]);
    }

    public function store(StoreLeaveRequestRequest $request, Company $company, CreateLeaveRequest $action): RedirectResponse
    {
        $this->assertActiveCompany($company);
        $data = $request->validated();
        $employee = $company->employees()->whereKey($data['employee_id'])->firstOrFail();
        $leaveType = $company->leaveTypes()->whereKey($data['leave_type_id'])->firstOrFail();

        try {
            $leaveRequest = $action->handle($employee, $leaveType, $data['start_date'], $data['end_date'], $data['duration'], $data['reason'] ?? null, $data['employee_note'] ?? null, $request->user());
        } catch (LeaveRequestConfigurationException|NoWorkScheduleAssignmentException|InvalidWorkScheduleConfigurationException|InvalidHolidayCalendarConfigurationException $exception) {
            throw ValidationException::withMessages(['start_date' => $exception->getMessage()]);
        }

        return redirect()->route('companies.leave-requests.show', [$company, $leaveRequest])->with('success', 'Leave request submitted for review.');
    }

    public function show(Request $request, Company $company, LeaveRequest $leaveRequest, LeaveBalanceCalculator $calculator, LeaveRequestReservationCalculator $reservations): Response
    {
        $this->assertActiveCompany($company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);
        Gate::authorize('view', $leaveRequest);
        $leaveRequest->load(['employee.department', 'employee.branch', 'leaveType', 'leavePolicy', 'assignment', 'balanceAccount', 'requester', 'canceller', 'reviewedBy', 'days.workSchedule', 'days.holiday']);
        $balance = $leaveRequest->balanceAccount ? $calculator->calculate($leaveRequest->balanceAccount) : null;
        $otherReserved = $leaveRequest->balanceAccount ? $reservations->pendingCents($leaveRequest->balanceAccount, $leaveRequest->id) : 0;
        $thisRequestReserved = $leaveRequest->status === LeaveRequestStatus::Pending ? LeaveAmount::toCents((string) $leaveRequest->requested_days) : 0;
        $pendingTotal = $otherReserved + $thisRequestReserved;
        $ledgerConsumption = LeaveLedgerEntry::query()->where('company_id', $company->id)
            ->where('transaction_type', 'leave_used')->where('reference_type', LeaveRequest::class)
            ->where('reference_id', $leaveRequest->id)->first();

        return Inertia::render('leave-requests/show', [
            'leaveRequest' => $this->serializeRequest($leaveRequest),
            'balance' => $balance ? [
                'ledger' => $balance->balance,
                'otherPendingReserved' => LeaveAmount::fromCents($otherReserved),
                'thisRequest' => LeaveAmount::fromCents($thisRequestReserved),
                'pending' => LeaveAmount::fromCents($pendingTotal),
                'projectedProtected' => LeaveAmount::fromCents(LeaveAmount::toCents($balance->balance) - $pendingTotal),
                'available' => LeaveAmount::fromCents(LeaveAmount::toCents($balance->balance) - $pendingTotal),
            ] : null,
            'canCancel' => $request->user()->can('cancel', $leaveRequest),
            'canCancelApproved' => $leaveRequest->status === LeaveRequestStatus::Approved
                && $request->user()->can('cancelApproved', $leaveRequest),
            'canApprove' => $request->user()->can('approve', $leaveRequest),
            'canReject' => $request->user()->can('reject', $leaveRequest),
            'approveUrl' => route('companies.leave-requests.approve', [$company, $leaveRequest]),
            'rejectUrl' => route('companies.leave-requests.reject', [$company, $leaveRequest]),
            'ledgerConsumption' => $ledgerConsumption ? ['amount' => (string) $ledgerConsumption->amount, 'effectiveDate' => $ledgerConsumption->effective_date->toDateString()] : null,
            'cancelUrl' => route('companies.leave-requests.cancel', [$company, $leaveRequest]),
            'cancelApprovedUrl' => route('companies.leave-requests.cancel-approved', [$company, $leaveRequest]),
            'backUrl' => route('companies.leave-requests.index', $company),
        ]);
    }

    public function cancel(CancelLeaveRequestRequest $request, Company $company, LeaveRequest $leaveRequest, CancelLeaveRequest $action): RedirectResponse
    {
        $this->assertActiveCompany($company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);
        $action->handle($leaveRequest, $request->user(), $request->validated('cancellation_reason'));

        return back()->with('success', 'Leave request cancelled.');
    }

    public function cancelApproved(
        CancelApprovedLeaveHttpRequest $request,
        Company $company,
        LeaveRequest $leaveRequest,
        CancelApprovedLeaveAndSynchronizeAttendance $action,
    ): RedirectResponse {
        $this->assertActiveCompany($company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);

        try {
            $result = $action->execute(
                $leaveRequest,
                $request->user(),
                $request->validated('cancellation_reason'),
            );
        } catch (ApprovedLeaveCancellationException $exception) {
            if (in_array($exception->reason, ['CANCELLATION_NOT_AUTHORIZED', 'REQUESTER_CANNOT_CANCEL_APPROVED_LEAVE'], true)) {
                abort(403);
            }

            throw ValidationException::withMessages([
                'leave_request' => $this->approvedCancellationFailureMessage($exception->reason),
            ]);
        }

        if ($result->attendanceSynchronizationSucceeded) {
            return back()->with('success', 'Approved leave cancelled successfully and attendance synchronized.');
        }

        $failedCount = max(count($result->attendanceFailedDates), 1);

        return back()->with('warning', "Approved leave was cancelled and the balance restored, but attendance synchronization needs attention for {$failedCount} date(s).");
    }

    private function approvedCancellationFailureMessage(string $reason): string
    {
        return match ($reason) {
            'LEAVE_BALANCE_PERIOD_CLOSED' => 'This leave belongs to a closed balance period and cannot be cancelled here.',
            'BALANCE_CAP_EXCEEDED' => 'Cancellation cannot restore the balance because it would exceed the leave balance limit.',
            'REQUEST_NOT_APPROVED', 'APPROVED_LEAVE_CANCELLATION_REQUIRED' => 'Only approved leave can be cancelled using this action.',
            'INVALID_CANCELLATION_REASON' => 'Enter a cancellation reason between 3 and 1000 characters.',
            default => 'Cancellation could not be completed because leave accounting needs review. No changes were made.',
        };
    }

    public function approve(ApproveLeaveRequestRequest $request, Company $company, LeaveRequest $leaveRequest, ApproveLeaveRequest $action): RedirectResponse
    {
        $this->assertActiveCompany($company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);
        $action->handle($leaveRequest, $request->user(), $company, $request->validated('review_note'));

        return redirect()->route('companies.leave-requests.show', [$company, $leaveRequest])->with('success', 'Leave request approved and balance consumed.');
    }

    public function reject(RejectLeaveRequestRequest $request, Company $company, LeaveRequest $leaveRequest, RejectLeaveRequest $action): RedirectResponse
    {
        $this->assertActiveCompany($company);
        abort_unless((int) $leaveRequest->company_id === (int) $company->id, 404);
        $action->handle($leaveRequest, $request->user(), $company, $request->validated('review_note'));

        return redirect()->route('companies.leave-requests.show', [$company, $leaveRequest])->with('success', 'Leave request rejected.');
    }

    private function assertActiveCompany(Company $company): void
    {
        abort_unless(app(ActiveCompany::class)->current()?->is($company), 404);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters, Company $company): void
    {
        $query->when($filters['search'] ?? null, function (Builder $builder, string $search): void {
            $builder->whereHas('employee', fn (Builder $employees): Builder => $employees->where('employee_code', 'like', "%{$search}%")
                ->orWhere('first_name', 'like', "%{$search}%")->orWhere('middle_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%"));
        })->when($filters['status'] ?? null, fn (Builder $builder, string $status): Builder => $builder->where('status', $status))
            ->when($filters['leave_type_id'] ?? null, fn (Builder $builder, int $typeId): Builder => $builder->where('leave_type_id', $typeId))
            ->when($filters['date_from'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('start_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('end_date', '<=', $date))
            ->when($filters['department_id'] ?? null, fn (Builder $builder, int $id): Builder => $builder->whereHas('employee', fn (Builder $employees): Builder => $employees->where('department_id', $id)->where('company_id', $company->id)))
            ->when($filters['branch_id'] ?? null, fn (Builder $builder, int $id): Builder => $builder->whereHas('employee', fn (Builder $employees): Builder => $employees->where('branch_id', $id)->where('company_id', $company->id)));
    }

    /** @return array<string, mixed> */
    private function serializeRequest(LeaveRequest $request): array
    {
        return [
            'id' => $request->id, 'employee' => ['id' => $request->employee->id, 'name' => $request->employee->full_name, 'code' => $request->employee->employee_code],
            'leaveType' => ['id' => $request->leaveType->id, 'name' => $request->leaveType->name, 'code' => $request->leaveType->code],
            'leavePolicy' => ['name' => $request->leavePolicy->name, 'code' => $request->leavePolicy->code],
            'assignmentId' => $request->employee_leave_policy_assignment_id, 'balanceAccountId' => $request->leave_balance_account_id,
            'startDate' => $request->start_date->toDateString(), 'endDate' => $request->end_date->toDateString(),
            'requestedDays' => (string) $request->requested_days, 'duration' => $request->duration, 'status' => $request->status->value,
            'reason' => $request->reason, 'employeeNote' => $request->employee_note, 'requestedBy' => $request->requester?->name, 'requestedAt' => $request->requested_at?->toDateTimeString(),
            'cancelledBy' => $request->canceller?->name, 'cancelledAt' => $request->cancelled_at?->toDateTimeString(), 'cancellationReason' => $request->cancellation_reason,
            'reviewedBy' => $request->reviewedBy?->name, 'reviewedAt' => $request->reviewed_at?->toDateTimeString(), 'reviewNote' => $request->review_note,
            'days' => $request->days->map(fn ($day): array => [
                'date' => $day->leave_date->toDateString(), 'type' => $day->day_type->value, 'amount' => (string) $day->requested_amount,
                'schedule' => $day->workSchedule?->name, 'holiday' => $day->holiday?->name,
            ])->all(),
        ];
    }
}
