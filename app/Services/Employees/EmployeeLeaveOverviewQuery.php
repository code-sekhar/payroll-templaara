<?php

namespace App\Services\Employees;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeaveRequestReservationCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final class EmployeeLeaveOverviewQuery
{
    public function __construct(
        private readonly LeaveBalanceCalculator $balanceCalculator,
        private readonly LeaveRequestReservationCalculator $reservationCalculator,
    ) {}

    public function forEmployee(Employee $resolvedEmployee, Company $company): EmployeeLeaveOverviewData
    {
        abort_unless((int) $resolvedEmployee->company_id === (int) $company->getKey(), 403);

        $today = CarbonImmutable::now($company->timezone ?: config('app.timezone', 'UTC'))->toDateString();
        $assignments = EmployeeLeavePolicyAssignment::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $resolvedEmployee->getKey())
            ->effectiveOn($today)
            ->whereHas('leaveType', fn (Builder $query): Builder => $query
                ->where('company_id', $company->getKey())
                ->where('status', LeaveTypeStatus::Active->value))
            ->whereHas('leavePolicy', fn (Builder $query): Builder => $query
                ->where('company_id', $company->getKey())
                ->where('status', LeavePolicyStatus::Active->value))
            ->with([
                'leaveType' => fn ($query) => $query
                    ->select(['id', 'company_id', 'name', 'status', 'sort_order', 'allows_half_day'])
                    ->where('company_id', $company->getKey()),
                'leavePolicy' => fn ($query) => $query
                    ->select(['id', 'company_id', 'leave_type_id', 'status', 'allows_half_day', 'requires_reason'])
                    ->where('company_id', $company->getKey())
                    ->where('status', LeavePolicyStatus::Active->value),
            ])
            ->orderBy('leave_type_id')
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get(['id', 'company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'effective_from', 'effective_to']);

        $accounts = EmployeeLeaveBalanceAccount::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $resolvedEmployee->getKey())
            ->whereIn('employee_leave_policy_assignment_id', $assignments->modelKeys())
            ->where('status', LeaveBalanceAccountStatus::Open->value)
            ->whereDate('period_start', '<=', $today)
            ->whereDate('period_end', '>=', $today)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get(['id', 'company_id', 'employee_id', 'leave_type_id', 'leave_policy_id', 'employee_leave_policy_assignment_id', 'period_start', 'period_end']);
        $accountsByAssignment = $accounts->groupBy('employee_leave_policy_assignment_id');
        $calculatedBalances = $this->balanceCalculator->calculateMany($accounts, $today);
        $pendingByAccount = $this->reservationCalculator->pendingCentsForAccounts($accounts);

        $applicationTypes = [];
        $balances = $assignments->map(function (EmployeeLeavePolicyAssignment $assignment) use ($company, $accountsByAssignment, $calculatedBalances, $pendingByAccount, &$applicationTypes): array {
            $leaveType = $assignment->leaveType;
            $leavePolicy = $assignment->leavePolicy;

            if ($leaveType === null || (int) $leaveType->company_id !== (int) $company->getKey()) {
                return [];
            }

            $currentAccounts = $accountsByAssignment->get($assignment->getKey(), collect());

            if ($leavePolicy === null || (int) $leavePolicy->company_id !== (int) $company->getKey()
                || (int) $leavePolicy->leave_type_id !== (int) $assignment->leave_type_id || $currentAccounts->count() !== 1) {
                $applicationTypes[] = [
                    'id' => (int) $leaveType->getKey(),
                    'name' => $leaveType->name,
                    'allowsHalfDay' => (bool) $leaveType->allows_half_day && (bool) $leavePolicy?->allows_half_day,
                    'reasonRequired' => (bool) $leavePolicy?->requires_reason,
                    'configured' => false,
                    'available' => null,
                    'pending' => null,
                ];

                return [
                    'leaveType' => $leaveType->name,
                    'periodStart' => null,
                    'periodEnd' => null,
                    'configured' => false,
                    'available' => null,
                    'used' => null,
                    'pending' => null,
                ];
            }

            /** @var EmployeeLeaveBalanceAccount $account */
            $account = $currentAccounts->first();
            $calculated = $calculatedBalances[$account->getKey()];
            $pendingCents = $pendingByAccount[$account->getKey()] ?? 0;
            $usedCents = max(0, -(
                LeaveAmount::toCents($calculated->leaveUsed)
                + LeaveAmount::toCents($calculated->leaveReversed)
            ));
            $available = LeaveAmount::fromCents(LeaveAmount::toCents($calculated->balance) - $pendingCents);
            $pending = LeaveAmount::fromCents($pendingCents);
            $applicationTypes[] = [
                'id' => (int) $leaveType->getKey(),
                'name' => $leaveType->name,
                'allowsHalfDay' => (bool) $leaveType->allows_half_day && (bool) $leavePolicy->allows_half_day,
                'reasonRequired' => (bool) $leavePolicy->requires_reason,
                'configured' => true,
                'available' => $available,
                'pending' => $pending,
            ];

            return [
                'leaveType' => $leaveType->name,
                'periodStart' => $account->period_start->toDateString(),
                'periodEnd' => $account->period_end->toDateString(),
                'configured' => true,
                'available' => $available,
                'used' => LeaveAmount::fromCents($usedCents),
                'pending' => $pending,
            ];
        })->filter()->values()->all();

        $recentRequests = LeaveRequest::query()
            ->where('company_id', $company->getKey())
            ->where('employee_id', $resolvedEmployee->getKey())
            ->with(['leaveType' => fn ($query) => $query
                ->select(['id', 'company_id', 'name'])
                ->where('company_id', $company->getKey())])
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'company_id', 'employee_id', 'leave_type_id', 'start_date', 'end_date', 'requested_days', 'status', 'requested_at'])
            ->map(fn (LeaveRequest $request): array => [
                'leaveType' => $request->leaveType?->name ?? 'Leave',
                'startDate' => $request->start_date->toDateString(),
                'endDate' => $request->end_date->toDateString(),
                'requestedDays' => (string) $request->requested_days,
                'status' => $request->status->value,
                'href' => route('employee.leave.requests.show', $request->getKey()),
            ])
            ->all();

        return new EmployeeLeaveOverviewData([
            'asOfDate' => $today,
            'hasApplicableLeaveTypes' => $assignments->isNotEmpty(),
            'balances' => $balances,
            'recentRequests' => $recentRequests,
        ], $applicationTypes);
    }
}
