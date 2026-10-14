<?php

namespace App\Actions\LeaveRequests;

use App\Actions\LeaveBalances\PostLeaveLedgerEntry;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Attendance\ApprovedLeaveAttendanceSynchronizer;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Services\Notifications\LeaveLifecycleNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveLeaveRequest
{
    public function __construct(
        private readonly LeaveBalanceCalculator $balanceCalculator,
        private readonly LeaveRequestReservationCalculator $reservationCalculator,
        private readonly PostLeaveLedgerEntry $ledgerPoster,
        private readonly ApprovedLeaveAttendanceSynchronizer $attendanceSynchronizer,
        private readonly LeaveLifecycleNotificationService $notifications,
        private readonly LeaveRequestReviewerScope $reviewerScope,
    ) {}

    public function handle(LeaveRequest $leaveRequest, User $reviewer, Company $activeCompany, ?string $reviewNote = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $reviewer, $activeCompany, $reviewNote): LeaveRequest {
            $request = LeaveRequest::query()->where('company_id', $activeCompany->getKey())
                ->whereKey($leaveRequest->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequestStatus::Pending) {
                throw ValidationException::withMessages(['request' => 'Only pending leave requests can be approved.']);
            }
            if ((int) $request->requested_by === (int) $reviewer->getKey()) {
                throw ValidationException::withMessages(['request' => 'A requester cannot review their own leave request.']);
            }
            $employee = Employee::withTrashed()->where('company_id', $request->company_id)
                ->whereKey($request->employee_id)->lockForUpdate()->first();
            if (! $employee) {
                throw ValidationException::withMessages(['request' => 'The employee reference is no longer valid.']);
            }
            if (! $this->reviewerScope->allowsDecision($reviewer, $request, $activeCompany, $employee, 'leave_request.approve')) {
                throw new AuthorizationException('You are no longer authorized to approve this leave request.');
            }

            $account = EmployeeLeaveBalanceAccount::query()->where('company_id', $request->company_id)
                ->whereKey($request->leave_balance_account_id)->lockForUpdate()->first();
            if (! $account || $account->status !== LeaveBalanceAccountStatus::Open) {
                throw ValidationException::withMessages(['request' => 'The leave balance account is missing or closed.']);
            }

            $this->validateReferences($request, $employee, $account);
            $days = $request->days()->orderBy('leave_date')->orderBy('id')->lockForUpdate()->get();
            $chargeableDates = $this->validateSnapshots($request, $days);
            $this->validateNoApprovedDateConflict($request, $chargeableDates);

            $balance = $this->balanceCalculator->calculate($account);
            $otherPendingCents = $this->reservationCalculator->pendingCents($account, (int) $request->getKey());
            $requestedCents = LeaveAmount::toCents((string) $request->requested_days);
            if ($requestedCents <= 0) {
                throw ValidationException::withMessages(['request' => 'The request must have a positive chargeable amount.']);
            }

            $policy = $account->leavePolicy;
            if (! $policy || $policy->trashed()) {
                throw ValidationException::withMessages(['request' => 'The referenced leave policy is unavailable.']);
            }

            $projectedCents = LeaveAmount::toCents($balance->balance) - $otherPendingCents - $requestedCents;
            $minimumCents = 0;
            if ($policy->allow_negative_balance) {
                if ($policy->max_negative_balance_days === null || LeaveAmount::toCents((string) $policy->max_negative_balance_days) < 0) {
                    throw ValidationException::withMessages(['request' => 'The policy negative-balance configuration is invalid.']);
                }
                $minimumCents = -LeaveAmount::toCents((string) $policy->max_negative_balance_days);
            }
            if ($projectedCents < $minimumCents) {
                throw ValidationException::withMessages(['request' => 'Available balance is insufficient after preserving other pending requests.']);
            }

            $request->setRelation('balanceAccount', $account);
            $this->ledgerPoster->handleApprovedLeaveUsage($request, $reviewer);

            $request->forceFill([
                'status' => LeaveRequestStatus::Approved,
                'reviewed_by' => $reviewer->getKey(),
                'reviewed_at' => CarbonImmutable::now('UTC'),
                'review_note' => filled($reviewNote) ? trim($reviewNote) : null,
            ])->save();

            $this->attendanceSynchronizer->synchronize($request, $reviewer);
            $this->notifications->requestApproved($request);

            return $request->refresh()->load(['employee', 'leaveType', 'leavePolicy', 'assignment', 'balanceAccount', 'requester', 'reviewedBy', 'days']);
        }, attempts: 3);
    }

    private function validateReferences(LeaveRequest $request, Employee $employee, EmployeeLeaveBalanceAccount $account): void
    {
        $companyId = (int) $request->company_id;
        $employeeId = (int) $request->employee_id;
        $leaveType = LeaveType::withTrashed()->whereKey($request->leave_type_id)->first();
        $policy = LeavePolicy::withTrashed()->whereKey($request->leave_policy_id)->first();
        $assignment = EmployeeLeavePolicyAssignment::withTrashed()->whereKey($request->employee_leave_policy_assignment_id)->first();

        if ((int) $employee->company_id !== $companyId
            || ! $leaveType || (int) $leaveType->company_id !== $companyId
            || ! $policy || (int) $policy->company_id !== $companyId || (int) $policy->leave_type_id !== (int) $request->leave_type_id
            || ! $assignment || (int) $assignment->company_id !== $companyId || (int) $assignment->employee_id !== $employeeId
            || (int) $assignment->leave_type_id !== (int) $request->leave_type_id
            || (int) $assignment->leave_policy_id !== (int) $request->leave_policy_id
            || ! $assignment->effective_from || $assignment->effective_from->toDateString() > $request->start_date->toDateString()
            || ($assignment->effective_to && $assignment->effective_to->toDateString() < $request->end_date->toDateString())) {
            throw ValidationException::withMessages(['request' => 'The request policy, employee, or assignment references are inconsistent.']);
        }

        if ((int) $account->company_id !== $companyId || (int) $account->employee_id !== $employeeId
            || (int) $account->leave_type_id !== (int) $request->leave_type_id
            || (int) $account->leave_policy_id !== (int) $request->leave_policy_id
            || (int) $account->employee_leave_policy_assignment_id !== (int) $request->employee_leave_policy_assignment_id
            || $account->period_start->toDateString() > $request->start_date->toDateString()
            || $account->period_end->toDateString() < $request->end_date->toDateString()) {
            throw ValidationException::withMessages(['request' => 'The request does not match its balance account ownership or period.']);
        }
    }

    /** @param Collection<int, LeaveRequestDay> $days
     * @return list<string>
     */
    private function validateSnapshots(LeaveRequest $request, Collection $days): array
    {
        $expectedDateCount = (int) $request->start_date->diffInDays($request->end_date) + 1;
        if ($days->count() !== $expectedDateCount) {
            throw ValidationException::withMessages(['request' => 'The request day snapshots are incomplete.']);
        }

        $sumCents = 0;
        $dates = [];
        $chargeableDates = [];
        foreach ($days as $day) {
            $date = $day->leave_date->toDateString();
            $amountCents = LeaveAmount::toCents((string) $day->requested_amount);
            if ((int) $day->company_id !== (int) $request->company_id || (int) $day->employee_id !== (int) $request->employee_id
                || (int) $day->leave_request_id !== (int) $request->getKey()
                || $date < $request->start_date->toDateString() || $date > $request->end_date->toDateString()
                || isset($dates[$date]) || $amountCents < 0) {
                throw ValidationException::withMessages(['request' => 'A request day snapshot is invalid or inconsistent.']);
            }

            $dates[$date] = true;
            $sumCents += $amountCents;
            if ($day->day_type === LeaveRequestDayType::FullDay && $amountCents !== 100
                || $day->day_type === LeaveRequestDayType::HalfDay && $amountCents !== 50
                || in_array($day->day_type, [LeaveRequestDayType::OffDay, LeaveRequestDayType::Holiday], true) && $amountCents !== 0) {
                throw ValidationException::withMessages(['request' => 'A request day snapshot amount does not match its day type.']);
            }

            if ($amountCents > 0) {
                $chargeableDates[] = $date;
            }
        }

        if ($sumCents !== LeaveAmount::toCents((string) $request->requested_days) || $sumCents <= 0) {
            throw ValidationException::withMessages(['request' => 'The request amount does not match its immutable day snapshots.']);
        }

        return $chargeableDates;
    }

    /** @param list<string> $chargeableDates */
    private function validateNoApprovedDateConflict(LeaveRequest $request, array $chargeableDates): void
    {
        if ($chargeableDates === []) {
            throw ValidationException::withMessages(['request' => 'The request contains no chargeable leave dates.']);
        }

        $conflict = LeaveRequest::query()->where('company_id', $request->company_id)->where('employee_id', $request->employee_id)
            ->where('id', '<>', $request->getKey())->where('status', LeaveRequestStatus::Approved->value)
            ->whereHas('days', function ($query) use ($chargeableDates): void {
                $query->where('requested_amount', '>', 0)->where(function ($dates) use ($chargeableDates): void {
                    foreach ($chargeableDates as $index => $date) {
                        $method = $index === 0 ? 'whereDate' : 'orWhereDate';
                        $dates->{$method}('leave_date', $date);
                    }
                });
            })->exists();

        if ($conflict) {
            throw ValidationException::withMessages(['request' => 'Another approved leave request already uses one or more requested dates.']);
        }
    }
}
