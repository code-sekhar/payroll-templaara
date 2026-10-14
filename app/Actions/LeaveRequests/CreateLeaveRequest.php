<?php

namespace App\Actions\LeaveRequests;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveRequestStatus;
use App\Enums\LeaveTypeStatus;
use App\Exceptions\Leave\LeaveRequestConfigurationException;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveDayResolver;
use App\Services\Leave\LeaveRequestReservationCalculator;
use App\Services\Notifications\LeaveLifecycleNotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateLeaveRequest
{
    public function __construct(
        private readonly LeaveDayResolver $leaveDayResolver,
        private readonly LeaveBalanceCalculator $balanceCalculator,
        private readonly LeaveRequestReservationCalculator $reservationCalculator,
        private readonly LeaveLifecycleNotificationService $notifications,
    ) {}

    public function handle(Employee $employee, LeaveType $leaveType, string $startDate, string $endDate, string $duration, ?string $reason, ?string $employeeNote, User $requester): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $leaveType, $startDate, $endDate, $duration, $reason, $employeeNote, $requester): LeaveRequest {
            $employee = Employee::query()->whereKey($employee->getKey())->where('company_id', $employee->company_id)->lockForUpdate()->firstOrFail();
            if ($employee->trashed() || (int) $leaveType->company_id !== (int) $employee->company_id || $leaveType->trashed() || $leaveType->status !== LeaveTypeStatus::Active) {
                throw new LeaveRequestConfigurationException('INVALID_EMPLOYEE_OR_LEAVE_TYPE', 'Select an active employee and leave type from the active company.');
            }

            $start = CarbonImmutable::parse($startDate, $employee->company->timezone ?: config('app.timezone'))->startOfDay();
            $end = CarbonImmutable::parse($endDate, $employee->company->timezone ?: config('app.timezone'))->startOfDay();
            if ($end->lt($start) || $start->diffInDays($end) > 365) {
                throw ValidationException::withMessages(['end_date' => 'The leave period must be valid and no longer than 366 calendar days.']);
            }

            $assignment = null;
            for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                $dateAssignment = $employee->leavePolicyAssignmentForDate($leaveType, $date->toDateString());
                if (! $dateAssignment || (int) $dateAssignment->company_id !== (int) $employee->company_id || (int) $dateAssignment->leave_type_id !== (int) $leaveType->id) {
                    throw new LeaveRequestConfigurationException('NO_LEAVE_POLICY_ASSIGNED', 'No leave policy is assigned for the selected period.');
                }
                if ($assignment && $assignment->isNot($dateAssignment)) {
                    throw new LeaveRequestConfigurationException('LEAVE_POLICY_BOUNDARY_CROSSED', 'This request crosses a leave policy assignment boundary. Submit separate requests for each policy period.');
                }
                $assignment = $dateAssignment;
            }

            $policy = $assignment?->leavePolicy;
            if (! $assignment || ! $policy || $policy->trashed() || (int) $policy->company_id !== (int) $employee->company_id
                || (int) $policy->leave_type_id !== (int) $leaveType->id || $policy->status !== LeavePolicyStatus::Active) {
                throw new LeaveRequestConfigurationException('INVALID_LEAVE_POLICY', 'The leave policy configuration is unavailable or inactive for the selected period.');
            }
            $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
            if ($reason === null && $policy->requires_reason) {
                throw ValidationException::withMessages(['reason' => 'A reason is required for this leave type.']);
            }

            $isHalfDay = $duration === 'half_day';
            if ($isHalfDay && (! $leaveType->allows_half_day || ! $policy->allows_half_day)) {
                throw ValidationException::withMessages(['duration' => 'Half-day leave is not allowed by the selected leave type and policy.']);
            }

            $period = $this->leaveDayResolver->resolve($employee, $start, $end, $isHalfDay);
            if (LeaveAmount::toCents($period->requestedDays) === 0) {
                throw ValidationException::withMessages(['start_date' => 'The selected dates contain no chargeable working days.']);
            }

            $requestedCents = LeaveAmount::toCents($period->requestedDays);
            $minimumCents = LeaveAmount::toCents((string) $policy->minimum_request_days);
            $maximumCents = $policy->maximum_request_days === null ? null : LeaveAmount::toCents((string) $policy->maximum_request_days);
            if ($requestedCents < $minimumCents) {
                throw ValidationException::withMessages(['start_date' => 'The requested chargeable days are below the policy minimum.']);
            }
            if ($maximumCents !== null && $requestedCents > $maximumCents) {
                throw ValidationException::withMessages(['end_date' => 'The requested chargeable days exceed the policy maximum.']);
            }
            $noticeDays = (int) $policy->minimum_notice_days;
            $today = CarbonImmutable::now($employee->company->timezone ?: config('app.timezone'))->startOfDay();
            if ($start->gte($today) && $start->lt($today->addDays($noticeDays))) {
                throw ValidationException::withMessages(['start_date' => "This policy requires at least {$noticeDays} days' notice."]);
            }

            $accountQuery = EmployeeLeaveBalanceAccount::query()->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->id)->where('leave_type_id', $leaveType->id)
                ->where('leave_policy_id', $policy->id)->where('employee_leave_policy_assignment_id', $assignment->id)
                ->whereDate('period_start', '<=', $start->toDateString())->whereDate('period_end', '>=', $end->toDateString());
            $account = $accountQuery->lockForUpdate()->first();
            if (! $account) {
                $overlappingAccount = EmployeeLeaveBalanceAccount::query()->where('company_id', $employee->company_id)
                    ->where('employee_id', $employee->id)->where('leave_type_id', $leaveType->id)
                    ->whereDate('period_start', '<=', $end->toDateString())->whereDate('period_end', '>=', $start->toDateString())->exists();
                $reasonCode = $overlappingAccount ? 'LEAVE_BALANCE_ACCOUNT_BOUNDARY_CROSSED' : 'LEAVE_BALANCE_ACCOUNT_MISSING';
                $message = $overlappingAccount
                    ? 'This request crosses a leave balance period boundary. Submit separate requests for each account period.'
                    : 'Leave balance is not configured for this period. Please contact HR.';
                throw new LeaveRequestConfigurationException($reasonCode, $message);
            }
            if ($account->status !== LeaveBalanceAccountStatus::Open) {
                throw new LeaveRequestConfigurationException('LEAVE_BALANCE_ACCOUNT_CLOSED', 'The leave balance account for this period is closed.');
            }

            $chargeableDates = collect($period->days)->filter(fn ($day): bool => LeaveAmount::toCents($day->requestedAmount) > 0)
                ->map(fn ($day): string => $day->date->toDateString())->all();
            $overlappingRequest = LeaveRequest::query()->where('company_id', $employee->company_id)->where('employee_id', $employee->id)
                ->whereIn('status', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value])
                ->whereHas('days', function (Builder $query) use ($chargeableDates): Builder {
                    return $query->where(function (Builder $dates) use ($chargeableDates): void {
                        foreach ($chargeableDates as $index => $date) {
                            if ($index === 0) {
                                $dates->whereDate('leave_date', $date);
                            } else {
                                $dates->orWhereDate('leave_date', $date);
                            }
                        }
                    })->where('requested_amount', '>', 0);
                })
                ->exists();
            if ($overlappingRequest) {
                throw ValidationException::withMessages(['start_date' => 'An existing pending or approved leave request already covers one or more selected working dates.']);
            }

            $balance = $this->balanceCalculator->calculate($account);
            $pendingCents = $this->reservationCalculator->pendingCents($account);
            $projectedCents = LeaveAmount::toCents($balance->balance) - $pendingCents - $requestedCents;
            if (! $policy->allow_negative_balance && $projectedCents < 0) {
                throw ValidationException::withMessages(['leave_type_id' => 'The available leave balance is insufficient after pending requests.']);
            }
            if ($policy->allow_negative_balance && $projectedCents < -LeaveAmount::toCents((string) $policy->max_negative_balance_days)) {
                throw ValidationException::withMessages(['leave_type_id' => 'This request exceeds the policy negative-balance limit.']);
            }

            $request = LeaveRequest::query()->create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'leave_type_id' => $leaveType->id,
                'leave_policy_id' => $policy->id,
                'employee_leave_policy_assignment_id' => $assignment->id,
                'leave_balance_account_id' => $account->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'requested_days' => $period->requestedDays,
                'duration' => $duration,
                'status' => LeaveRequestStatus::Pending,
                'reason' => $reason,
                'employee_note' => $employeeNote,
                'requested_by' => $requester->id,
                'requested_at' => now(),
            ]);

            foreach ($period->days as $day) {
                $request->days()->create([
                    'company_id' => $employee->company_id,
                    'employee_id' => $employee->id,
                    'leave_date' => $day->date->toDateString(),
                    'day_type' => $day->dayType,
                    'requested_amount' => $day->requestedAmount,
                    'work_schedule_id' => $day->workSchedule->workSchedule->id,
                    'work_schedule_assignment_id' => $day->workSchedule->assignment->id,
                    'holiday_calendar_id' => $day->holiday->calendar?->id,
                    'holiday_id' => $day->holiday->holiday?->id,
                ]);
            }

            $this->notifications->requestSubmitted($request, $requester);

            return $request->load(['employee', 'leaveType', 'leavePolicy', 'assignment', 'balanceAccount', 'days']);
        }, 3);
    }
}
