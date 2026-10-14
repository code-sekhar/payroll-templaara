<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use Carbon\CarbonImmutable;

final readonly class LeaveAccrualReconciliationResult
{
    public function __construct(
        public EmployeeLeaveBalanceAccount $balanceAccount,
        public CalculatedLeaveAccrual $calculatedAccrual,
        public CarbonImmutable $asOfDate,
        public string $targetAccruedAmount,
        public string $previouslyPostedAccrualAmount,
        public string $postedAmount,
        public ?LeaveLedgerEntry $ledgerEntry,
        public bool $wasPosted,
        public bool $isUpToDate,
    ) {}
}
