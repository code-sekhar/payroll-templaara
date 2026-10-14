<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;

final readonly class ApprovedLeaveCancellationResult
{
    public function __construct(
        public LeaveRequest $leaveRequest,
        public EmployeeLeaveBalanceAccount $balanceAccount,
        public LeaveLedgerEntry $originalUsageEntry,
        public LeaveLedgerEntry $reversalEntry,
        public string $reversedAmount,
        public bool $wasCancelledNow,
        public bool $alreadyCancelled,
        public bool $isUpToDate,
    ) {}
}
