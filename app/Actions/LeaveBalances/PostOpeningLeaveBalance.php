<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveLedgerTransactionType;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\User;

class PostOpeningLeaveBalance
{
    public function __construct(private readonly PostLeaveLedgerEntry $postLeaveLedgerEntry) {}

    /** @param array{amount: string, effective_date: string, description: string} $attributes */
    public function handle(EmployeeLeaveBalanceAccount $account, User $actor, array $attributes): LeaveLedgerEntry
    {
        return $this->postLeaveLedgerEntry->handle(
            $account,
            $actor,
            LeaveLedgerTransactionType::OpeningBalance,
            $attributes['amount'],
            $attributes['effective_date'],
            $attributes['description'],
        );
    }
}
