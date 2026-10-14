<?php

namespace App\Services\Leave;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;

final readonly class LeavePeriodFinalizationResult
{
    public function __construct(
        public EmployeeLeaveBalanceAccount $sourceAccount,
        public ?EmployeeLeaveBalanceAccount $destinationAccount,
        public CalculatedLeavePeriodClosing $closingPreview,
        public ?LeavePeriodClosingAccountingResult $accountingResult,
        public string $preDispositionBalance,
        public string $carryForwardAmount,
        public string $expiryAmount,
        public string $sourceFinalLedgerBalance,
        public ?string $destinationResultingBalance,
        public ?LeaveLedgerEntry $carryForwardLedgerEntry,
        public ?LeaveLedgerEntry $expiryLedgerEntry,
        public bool $wasClosedNow,
        public bool $alreadyFinalized,
        public bool $isUpToDate,
    ) {}
}
