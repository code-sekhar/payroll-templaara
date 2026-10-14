<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Exceptions\Leave\LeaveAccrualReconciliationException;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\CalculatedLeaveAccrual;
use App\Services\Leave\LeaveAccrualCalculator;
use App\Services\Leave\LeaveAccrualReconciliationResult;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class ReconcileLeaveAccrual
{
    public function __construct(
        private readonly LeaveAccrualCalculator $calculator,
        private readonly PostLeaveLedgerEntry $ledgerPoster,
    ) {}

    public function execute(
        EmployeeLeaveBalanceAccount $balanceAccount,
        CarbonInterface $asOfDate,
        ?User $actor = null,
    ): LeaveAccrualReconciliationResult {
        return DB::transaction(function () use ($balanceAccount, $asOfDate, $actor): LeaveAccrualReconciliationResult {
            $lockedAccount = EmployeeLeaveBalanceAccount::query()
                ->where('company_id', $balanceAccount->company_id)
                ->whereKey($balanceAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $calculated = $this->calculator->calculate($lockedAccount, $asOfDate);
            $targetCents = LeaveAmount::toCents($calculated->accruedAmount);
            $postedCents = $this->postedAccrualCents($lockedAccount);

            if ($postedCents > $targetCents) {
                throw new LeaveAccrualReconciliationException(
                    (int) $lockedAccount->getKey(),
                    'POSTED_ACCRUAL_EXCEEDS_CALCULATED_TARGET',
                );
            }

            $missingCents = $targetCents - $postedCents;
            if ($missingCents === 0) {
                return $this->result($lockedAccount, $calculated, $postedCents, 0, null);
            }

            if ($lockedAccount->status !== LeaveBalanceAccountStatus::Open) {
                throw new LeaveAccrualReconciliationException(
                    (int) $lockedAccount->getKey(),
                    'BALANCE_ACCOUNT_CLOSED',
                );
            }

            $effectiveDate = $calculated->effectiveAsOfDate->toDateString();
            $entry = $this->ledgerPoster->postAccrualOnLockedAccount(
                $lockedAccount,
                LeaveAmount::fromCents($missingCents),
                $effectiveDate,
                $actor,
                "Leave accrual reconciliation through {$effectiveDate}",
            );

            return $this->result($lockedAccount, $calculated, $postedCents, $missingCents, $entry);
        }, attempts: 3);
    }

    private function postedAccrualCents(EmployeeLeaveBalanceAccount $account): int
    {
        $entries = LeaveLedgerEntry::query()
            ->where('company_id', $account->company_id)
            ->where('leave_balance_account_id', $account->getKey())
            ->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)
            ->orderBy('id')
            ->get(['amount']);

        $totalCents = 0;
        foreach ($entries as $entry) {
            $amountCents = LeaveAmount::toCents((string) $entry->getRawOriginal('amount'));
            if ($amountCents <= 0) {
                throw new LeaveAccrualReconciliationException(
                    (int) $account->getKey(),
                    'INVALID_POSTED_ACCRUAL_AMOUNT',
                );
            }

            $totalCents += $amountCents;
        }

        return $totalCents;
    }

    private function result(
        EmployeeLeaveBalanceAccount $account,
        CalculatedLeaveAccrual $calculated,
        int $previouslyPostedCents,
        int $postedNowCents,
        ?LeaveLedgerEntry $entry,
    ): LeaveAccrualReconciliationResult {
        return new LeaveAccrualReconciliationResult(
            $account,
            $calculated,
            $calculated->asOfDate,
            $calculated->accruedAmount,
            LeaveAmount::fromCents($previouslyPostedCents),
            LeaveAmount::fromCents($postedNowCents),
            $entry,
            $entry !== null,
            true,
        );
    }
}
