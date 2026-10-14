<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveBalanceAccountStatus;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\User;
use App\Services\Leave\LeavePeriodClosingAccountingResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class CloseLeaveBalanceAccount
{
    public function handle(EmployeeLeaveBalanceAccount $account, User $actor): EmployeeLeaveBalanceAccount
    {
        $account->loadMissing('company');
        $timezone = $account->company?->timezone ?: config('app.timezone', 'UTC');

        return app(FinalizeLeavePeriod::class)
            ->execute($account, CarbonImmutable::now($timezone), $actor)
            ->sourceAccount;
    }

    /**
     * Completes the existing lifecycle transition when a caller already owns the account lock.
     */
    public function closeLockedAccount(
        EmployeeLeaveBalanceAccount $lockedAccount,
        ?User $actor,
        LeavePeriodClosingAccountingResult $accountingResult,
    ): EmployeeLeaveBalanceAccount {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Closing a locked leave balance account requires an active transaction.');
        }

        if ($lockedAccount->status !== LeaveBalanceAccountStatus::Open) {
            throw ValidationException::withMessages(['account' => 'This balance account is already closed.']);
        }

        if (! $accountingResult->isUpToDate
            || (int) $accountingResult->sourceAccount->getKey() !== (int) $lockedAccount->getKey()
            || (int) $accountingResult->closingPreview->balanceAccount->getKey() !== (int) $lockedAccount->getKey()) {
            throw new LogicException('A balance account can only close after its period accounting is verified.');
        }

        $lockedAccount->update([
            'status' => LeaveBalanceAccountStatus::Closed,
            'updated_by' => $actor?->id,
        ]);

        return $lockedAccount->refresh();
    }
}
