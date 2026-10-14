<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\Leave\InvalidApprovedLeaveConfigurationException;
use App\Models\Employee;
use App\Models\LeaveRequestDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final class ApprovedLeaveResolver
{
    public function resolve(Employee $employee, CarbonInterface $date): ResolvedApprovedLeave
    {
        $calendarDate = CarbonImmutable::parse(
            $date->format('Y-m-d'),
            $employee->company?->timezone ?: config('app.timezone', 'UTC'),
        )->startOfDay();

        $days = LeaveRequestDay::query()
            ->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->getKey())
            ->whereDate('leave_date', $calendarDate->toDateString())
            ->where('requested_amount', '>', 0)
            ->whereHas('request', fn ($query) => $query
                ->where('company_id', $employee->company_id)
                ->where('employee_id', $employee->getKey())
                ->where('status', LeaveRequestStatus::Approved->value))
            ->with(['request.leaveType', 'request.leavePolicy', 'request.balanceAccount'])
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($days->count() > 1) {
            throw new InvalidApprovedLeaveConfigurationException(
                (int) $employee->getKey(),
                $calendarDate,
                'MULTIPLE_APPROVED_LEAVES_FOR_DATE',
            );
        }

        $day = $days->first();

        if (! $day) {
            return ResolvedApprovedLeave::none($employee, $calendarDate);
        }

        $request = $day->request;
        $leaveType = $request?->leaveType;
        $leavePolicy = $request?->leavePolicy;
        $account = $request?->balanceAccount;

        if (! $request
            || ! $leaveType
            || ! $leavePolicy
            || (int) $day->company_id !== (int) $employee->company_id
            || (int) $day->employee_id !== (int) $employee->getKey()
            || (int) $request->company_id !== (int) $employee->company_id
            || (int) $request->employee_id !== (int) $employee->getKey()
            || (int) $request->leave_type_id !== (int) $leaveType->getKey()
            || (int) $request->leave_policy_id !== (int) $leavePolicy->getKey()
            || (int) $leaveType->company_id !== (int) $employee->company_id
            || (int) $leavePolicy->company_id !== (int) $employee->company_id
            || ($account && ((int) $account->company_id !== (int) $employee->company_id
                || (int) $account->employee_id !== (int) $employee->getKey()
                || (int) $account->leave_type_id !== (int) $leaveType->getKey()
                || (int) $account->leave_policy_id !== (int) $leavePolicy->getKey()))) {
            throw new InvalidApprovedLeaveConfigurationException(
                (int) $employee->getKey(),
                $calendarDate,
                'APPROVED_LEAVE_TENANT_OR_REFERENCE_MISMATCH',
            );
        }

        $amount = (string) $day->requested_amount;

        try {
            $amountCents = LeaveAmount::toCents($amount);
        } catch (InvalidArgumentException) {
            throw new InvalidApprovedLeaveConfigurationException(
                (int) $employee->getKey(),
                $calendarDate,
                'APPROVED_LEAVE_AMOUNT_INVALID',
            );
        }

        if (! in_array($amountCents, [50, 100], true)
            || ($amountCents === 50 && $day->day_type !== LeaveRequestDayType::HalfDay)
            || ($amountCents === 100 && $day->day_type !== LeaveRequestDayType::FullDay)) {
            throw new InvalidApprovedLeaveConfigurationException(
                (int) $employee->getKey(),
                $calendarDate,
                'APPROVED_LEAVE_AMOUNT_OR_DAY_TYPE_INVALID',
            );
        }

        return new ResolvedApprovedLeave(
            $employee,
            $calendarDate,
            true,
            $request,
            $day,
            $leaveType,
            $leavePolicy,
            $account,
            $amount,
            $amountCents,
            $day->day_type,
        );
    }
}
