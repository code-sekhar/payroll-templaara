<?php

namespace App\Services\Leave;

use App\Enums\LeavePeriodClosingBlocker;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeavePolicy;
use Carbon\CarbonImmutable;

/** @phpstan-type ClosingBlockers list<LeavePeriodClosingBlocker> */
final readonly class CalculatedLeavePeriodClosing
{
    /**
     * @param  list<LeavePeriodClosingBlocker>  $blockers
     */
    public function __construct(
        public EmployeeLeaveBalanceAccount $balanceAccount,
        public LeavePolicy $policy,
        public EmployeeLeavePolicyAssignment $assignment,
        public CarbonImmutable $asOfDate,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $closingBalance,
        public string $negativeClosingBalance,
        public ?string $finalAccrualTarget,
        public string $postedAccrualAmount,
        public ?string $missingAccrualAmount,
        public bool $carryForwardEnabled,
        public ?string $carryForwardLimit,
        public string $carryForwardAmount,
        public string $expireAmount,
        public string $pendingReservationAmount,
        public CarbonImmutable $nextPeriodStart,
        public CarbonImmutable $nextPeriodEnd,
        public ?EmployeeLeaveBalanceAccount $nextAccount,
        public bool $nextAccountExists,
        public bool $alreadyClosed,
        public bool $eligibleToClose,
        public array $blockers,
    ) {}
}
