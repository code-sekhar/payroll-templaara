<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use Carbon\CarbonImmutable;

final readonly class CalculatedLeaveAccrual
{
    public function __construct(
        public EmployeeLeaveBalanceAccount $balanceAccount,
        public LeavePolicy $policy,
        public EmployeeLeavePolicyAssignment $assignment,
        public CarbonImmutable $asOfDate,
        public CarbonImmutable $effectiveAsOfDate,
        public string $entitlement,
        public string $accruedAmount,
        public string $remainingUnaccruedAmount,
        public int $totalAccrualPeriods,
        public int $earnedAccrualPeriods,
        public ?CarbonImmutable $nextAccrualDate,
        public bool $isFullyAccrued,
    ) {}
}
