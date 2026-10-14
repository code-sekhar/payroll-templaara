<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestDayType;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;

final readonly class ResolvedApprovedLeave
{
    public function __construct(
        public Employee $employee,
        public CarbonImmutable $date,
        private bool $approved,
        public ?LeaveRequest $leaveRequest,
        public ?LeaveRequestDay $leaveRequestDay,
        public ?LeaveType $leaveType,
        public ?LeavePolicy $leavePolicy,
        public ?EmployeeLeaveBalanceAccount $balanceAccount,
        public string $requestedAmount,
        public int $requestedAmountCents,
        public ?LeaveRequestDayType $dayType,
    ) {}

    public static function none(Employee $employee, CarbonImmutable $date): self
    {
        return new self($employee, $date, false, null, null, null, null, null, '0.00', 0, null);
    }

    public function hasApprovedLeave(): bool
    {
        return $this->approved;
    }

    public function isPaid(): ?bool
    {
        return $this->leaveType?->is_paid;
    }
}
