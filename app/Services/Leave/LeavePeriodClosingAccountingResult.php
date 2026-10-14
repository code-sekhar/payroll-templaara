<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;

final readonly class LeavePeriodClosingAccountingResult
{
    public function __construct(
        public EmployeeLeaveBalanceAccount $sourceAccount,
        public ?EmployeeLeaveBalanceAccount $nextAccount,
        public CalculatedLeavePeriodClosing $closingPreview,
        public string $carryForwardAmount,
        public string $expiryAmount,
        public ?LeaveLedgerEntry $carryForwardLedgerEntry,
        public ?LeaveLedgerEntry $expiryLedgerEntry,
        public bool $carryForwardPosted,
        public bool $expiryPosted,
        public bool $wasApplied,
        public bool $isUpToDate,
    ) {}
}
