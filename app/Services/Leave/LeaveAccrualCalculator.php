<?php

namespace App\Services\Leave;

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Exceptions\Leave\InvalidLeaveAccrualConfigurationException;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

final class LeaveAccrualCalculator
{
    public function calculate(EmployeeLeaveBalanceAccount $balanceAccount, CarbonInterface $asOfDate): CalculatedLeaveAccrual
    {
        $balanceAccount->loadMissing([
            'employee.company',
            'leaveType',
            'leavePolicy',
            'assignment',
        ]);

        $employee = $balanceAccount->employee;
        $leaveType = $balanceAccount->leaveType;
        $policy = $balanceAccount->leavePolicy;
        $assignment = $balanceAccount->assignment;

        if (! $employee || ! $leaveType || ! $policy || ! $assignment) {
            $this->invalid($balanceAccount, 'ACCOUNT_REFERENCE_MISSING');
        }

        if (! $employee->company
            || (int) $employee->company->getKey() !== (int) $balanceAccount->company_id
            || (int) $employee->getKey() !== (int) $balanceAccount->employee_id
            || (int) $employee->company_id !== (int) $balanceAccount->company_id
            || (int) $leaveType->getKey() !== (int) $balanceAccount->leave_type_id
            || (int) $leaveType->company_id !== (int) $balanceAccount->company_id
            || (int) $policy->getKey() !== (int) $balanceAccount->leave_policy_id
            || (int) $policy->company_id !== (int) $balanceAccount->company_id
            || (int) $policy->leave_type_id !== (int) $leaveType->getKey()
            || (int) $assignment->getKey() !== (int) $balanceAccount->employee_leave_policy_assignment_id
            || (int) $assignment->company_id !== (int) $balanceAccount->company_id
            || (int) $assignment->employee_id !== (int) $employee->getKey()
            || (int) $assignment->leave_type_id !== (int) $leaveType->getKey()
            || (int) $assignment->leave_policy_id !== (int) $policy->getKey()) {
            $this->invalid($balanceAccount, 'POLICY_ASSIGNMENT_MISMATCH');
        }

        if ($policy->trashed()) {
            $this->invalid($balanceAccount, 'POLICY_DELETED');
        }

        $timezone = $employee->company?->timezone ?: config('app.timezone', 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            $this->invalid($balanceAccount, 'INVALID_COMPANY_TIMEZONE');
        }

        $localAsOfDate = CarbonImmutable::instance($asOfDate)->setTimezone($timezone)->startOfDay();
        $periodStart = CarbonImmutable::parse($balanceAccount->period_start->toDateString(), $timezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($balanceAccount->period_end->toDateString(), $timezone)->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            $this->invalid($balanceAccount, 'INVALID_ACCOUNT_PERIOD');
        }

        $assignmentStart = CarbonImmutable::parse($assignment->effective_from->toDateString(), $timezone)->startOfDay();
        $assignmentEnd = $assignment->effective_to
            ? CarbonImmutable::parse($assignment->effective_to->toDateString(), $timezone)->startOfDay()
            : null;

        if ($periodStart->lt($assignmentStart) || ($assignmentEnd && $periodEnd->gt($assignmentEnd))) {
            $this->invalid($balanceAccount, 'ACCOUNT_PERIOD_OUTSIDE_ASSIGNMENT');
        }

        try {
            $entitlementCents = LeaveAmount::toCents((string) $policy->getRawOriginal('entitlement_days'));
        } catch (InvalidArgumentException) {
            $this->invalid($balanceAccount, 'INVALID_ENTITLEMENT');
        }

        if ($entitlementCents < 0) {
            $this->invalid($balanceAccount, 'INVALID_ENTITLEMENT');
        }

        $method = LeaveAccrualMethod::tryFrom((string) $policy->getRawOriginal('accrual_method'));
        $frequency = LeaveAccrualFrequency::tryFrom((string) $policy->getRawOriginal('accrual_frequency'));

        if (! $method || ! $frequency) {
            $this->invalid($balanceAccount, 'UNSUPPORTED_ACCRUAL_CONFIGURATION');
        }

        $effectiveAsOfDate = $localAsOfDate->lt($periodStart)
            ? $periodStart
            : ($localAsOfDate->gt($periodEnd) ? $periodEnd : $localAsOfDate);
        $isBeforePeriod = $localAsOfDate->lt($periodStart);

        if ($method === LeaveAccrualMethod::None) {
            if ($frequency !== LeaveAccrualFrequency::None) {
                $this->invalid($balanceAccount, 'ACCRUAL_FREQUENCY_WITHOUT_ACCRUAL_METHOD');
            }

            return $this->result(
                $balanceAccount,
                $policy,
                $assignment,
                $localAsOfDate,
                $effectiveAsOfDate,
                $entitlementCents,
                0,
                0,
                0,
                null,
            );
        }

        if ($method === LeaveAccrualMethod::Upfront) {
            if ($frequency !== LeaveAccrualFrequency::None) {
                $this->invalid($balanceAccount, 'ACCRUAL_FREQUENCY_WITH_UPFRONT_METHOD');
            }

            $earnedPeriods = $isBeforePeriod ? 0 : 1;
            $accruedCents = $earnedPeriods === 1 ? $entitlementCents : 0;

            return $this->result(
                $balanceAccount,
                $policy,
                $assignment,
                $localAsOfDate,
                $effectiveAsOfDate,
                $entitlementCents,
                1,
                $earnedPeriods,
                $accruedCents,
                $earnedPeriods === 0 ? $periodStart : null,
            );
        }

        if ($frequency !== LeaveAccrualFrequency::Monthly) {
            $this->invalid($balanceAccount, 'UNSUPPORTED_ACCRUAL_FREQUENCY');
        }

        $accrualDates = $this->monthlyAccrualDates($periodStart, $periodEnd);
        $earnedPeriods = $isBeforePeriod
            ? 0
            : count(array_filter($accrualDates, fn (CarbonImmutable $date): bool => $date->lte($effectiveAsOfDate)));
        $baseCents = intdiv($entitlementCents, count($accrualDates));
        $remainderCents = $entitlementCents % count($accrualDates);
        $accruedCents = ($baseCents * $earnedPeriods) + min($earnedPeriods, $remainderCents);
        $nextAccrualDate = $accrualDates[$earnedPeriods] ?? null;

        return $this->result(
            $balanceAccount,
            $policy,
            $assignment,
            $localAsOfDate,
            $effectiveAsOfDate,
            $entitlementCents,
            count($accrualDates),
            $earnedPeriods,
            $accruedCents,
            $nextAccrualDate,
        );
    }

    /** @return list<CarbonImmutable> */
    private function monthlyAccrualDates(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $dates = [];
        $candidate = $periodStart->endOfMonth()->startOfDay();

        if ($candidate->lte($periodStart)) {
            $candidate = $periodStart->addMonthNoOverflow()->endOfMonth()->startOfDay();
        }

        while ($candidate->lt($periodEnd)) {
            $dates[] = $candidate;
            $candidate = $candidate->addMonthNoOverflow()->endOfMonth()->startOfDay();
        }

        if ($dates === [] || end($dates)->toDateString() !== $periodEnd->toDateString()) {
            $dates[] = $periodEnd;
        }

        return $dates;
    }

    private function result(
        EmployeeLeaveBalanceAccount $balanceAccount,
        LeavePolicy $policy,
        EmployeeLeavePolicyAssignment $assignment,
        CarbonImmutable $asOfDate,
        CarbonImmutable $effectiveAsOfDate,
        int $entitlementCents,
        int $totalPeriods,
        int $earnedPeriods,
        int $accruedCents,
        ?CarbonImmutable $nextAccrualDate,
    ): CalculatedLeaveAccrual {
        $remainingCents = $entitlementCents - $accruedCents;

        return new CalculatedLeaveAccrual(
            $balanceAccount,
            $policy,
            $assignment,
            $asOfDate,
            $effectiveAsOfDate,
            LeaveAmount::fromCents($entitlementCents),
            LeaveAmount::fromCents($accruedCents),
            LeaveAmount::fromCents($remainingCents),
            $totalPeriods,
            $earnedPeriods,
            $nextAccrualDate,
            $remainingCents === 0,
        );
    }

    private function invalid(EmployeeLeaveBalanceAccount $account, string $reason): never
    {
        throw new InvalidLeaveAccrualConfigurationException(
            $account->getKey() ? (int) $account->getKey() : null,
            $reason,
        );
    }
}
