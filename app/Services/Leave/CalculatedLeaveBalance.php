<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeaveBalanceAccount;

final readonly class CalculatedLeaveBalance
{
    /**
     * @param  array<string, string>  $transactionTotals
     */
    public function __construct(
        public EmployeeLeaveBalanceAccount $account,
        public ?string $asOfDate,
        public string $openingBalance,
        public string $entitlement,
        public string $accrual,
        public string $carryForward,
        public string $positiveAdjustments,
        public string $negativeAdjustments,
        public string $leaveUsed,
        public string $leaveReversed,
        public string $expiry,
        public string $balance,
        public array $transactionTotals,
    ) {}
}
