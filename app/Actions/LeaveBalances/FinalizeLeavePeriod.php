<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePeriodClosingBlocker;
use App\Exceptions\Leave\LeavePeriodClosingAccountingException;
use App\Exceptions\Leave\LeavePeriodFinalizationException;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\CalculatedLeavePeriodClosing;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeaveBalanceCalculator;
use App\Services\Leave\LeavePeriodClosingCalculator;
use App\Services\Leave\LeavePeriodFinalizationResult;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class FinalizeLeavePeriod
{
    public function __construct(
        private readonly LeavePeriodClosingCalculator $calculator,
        private readonly ApplyLeavePeriodClosingAccounting $closingAccounting,
        private readonly CloseLeaveBalanceAccount $accountCloser,
        private readonly LeaveBalanceCalculator $balanceCalculator,
    ) {}

    public function execute(
        EmployeeLeaveBalanceAccount $balanceAccount,
        CarbonInterface $asOfDate,
        ?User $actor = null,
    ): LeavePeriodFinalizationResult {
        return DB::transaction(function () use ($balanceAccount, $asOfDate, $actor): LeavePeriodFinalizationResult {
            $source = EmployeeLeaveBalanceAccount::query()
                ->where('company_id', $balanceAccount->company_id)
                ->whereKey($balanceAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($source->status === LeaveBalanceAccountStatus::Closed) {
                return $this->verifyAlreadyFinalized($source, $asOfDate);
            }

            $preview = $this->calculator->calculate($source, $asOfDate);
            $this->assertEligible($source, $preview);

            try {
                // Part 2 uses a nested transaction on this connection. Its locks remain held by
                // this outer transaction, preserving source-then-destination lock order.
                $accountingResult = $this->closingAccounting->execute($source, $asOfDate, $actor);
            } catch (LeavePeriodClosingAccountingException $exception) {
                throw new LeavePeriodFinalizationException(
                    $exception->accountId,
                    $exception->reason,
                    $exception->blockers,
                );
            }

            $source->refresh();
            $destination = $accountingResult->nextAccount?->refresh();
            [$carryEntry, $expiryEntry] = $this->verifyAccounting($source, $destination, $preview);

            $closedSource = $this->accountCloser->closeLockedAccount($source, $actor, $accountingResult);
            $sourceFinalBalance = $this->balanceCalculator->calculate($closedSource, $preview->periodEnd->toDateString())->balance;
            $destinationBalance = $destination
                ? $this->balanceCalculator->calculate($destination, $preview->nextPeriodStart->toDateString())->balance
                : null;

            return new LeavePeriodFinalizationResult(
                sourceAccount: $closedSource,
                destinationAccount: $destination,
                closingPreview: $preview,
                accountingResult: $accountingResult,
                preDispositionBalance: $preview->closingBalance,
                carryForwardAmount: $preview->carryForwardAmount,
                expiryAmount: $preview->expireAmount,
                sourceFinalLedgerBalance: $sourceFinalBalance,
                destinationResultingBalance: $destinationBalance,
                carryForwardLedgerEntry: $carryEntry,
                expiryLedgerEntry: $expiryEntry,
                wasClosedNow: true,
                alreadyFinalized: false,
                isUpToDate: true,
            );
        }, attempts: 3);
    }

    private function verifyAlreadyFinalized(
        EmployeeLeaveBalanceAccount $source,
        CarbonInterface $asOfDate,
    ): LeavePeriodFinalizationResult {
        $preview = $this->calculator->calculateForFinalizationRetry($source, $asOfDate);
        $blockers = array_values(array_filter(
            $preview->blockers,
            fn (LeavePeriodClosingBlocker $blocker): bool => $blocker !== LeavePeriodClosingBlocker::AccountAlreadyClosed,
        ));

        if ($blockers !== []) {
            $this->fail($source, 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT', array_map(
                fn (LeavePeriodClosingBlocker $blocker): string => $blocker->value,
                $blockers,
            ));
        }

        $destination = null;
        if (LeaveAmount::toCents($preview->carryForwardAmount) > 0 && $preview->nextAccount) {
            $destination = EmployeeLeaveBalanceAccount::query()
                ->where('company_id', $source->company_id)
                ->whereKey($preview->nextAccount->getKey())
                ->lockForUpdate()
                ->first();
        }

        [$carryEntry, $expiryEntry] = $this->verifyAccounting($source, $destination, $preview, true);
        $sourceFinalBalance = $this->balanceCalculator->calculate($source, $preview->periodEnd->toDateString())->balance;
        $destinationBalance = $destination
            ? $this->balanceCalculator->calculate($destination, $preview->nextPeriodStart->toDateString())->balance
            : null;

        return new LeavePeriodFinalizationResult(
            sourceAccount: $source,
            destinationAccount: $destination,
            closingPreview: $preview,
            accountingResult: null,
            preDispositionBalance: $preview->closingBalance,
            carryForwardAmount: $preview->carryForwardAmount,
            expiryAmount: $preview->expireAmount,
            sourceFinalLedgerBalance: $sourceFinalBalance,
            destinationResultingBalance: $destinationBalance,
            carryForwardLedgerEntry: $carryEntry,
            expiryLedgerEntry: $expiryEntry,
            wasClosedNow: false,
            alreadyFinalized: true,
            isUpToDate: true,
        );
    }

    /** @return array{?LeaveLedgerEntry, ?LeaveLedgerEntry} */
    private function verifyAccounting(
        EmployeeLeaveBalanceAccount $source,
        ?EmployeeLeaveBalanceAccount $destination,
        CalculatedLeavePeriodClosing $preview,
        bool $closedRetry = false,
    ): array {
        $carryEntries = $this->closingEntries($source, LeaveLedgerTransactionType::CarryForward);
        $expiryEntries = $this->closingEntries($source, LeaveLedgerTransactionType::Expiry, (int) $source->getKey());
        $carryCents = LeaveAmount::toCents($preview->carryForwardAmount);
        $expiryCents = LeaveAmount::toCents($preview->expireAmount);

        $carryEntry = null;
        if ($carryCents === 0) {
            if ($carryEntries->isNotEmpty()) {
                $this->fail($source, 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT');
            }
        } else {
            if (! $destination || $carryEntries->count() !== 1) {
                $this->fail($source, $closedRetry ? 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT' : 'FINAL_ACCOUNTING_VERIFICATION_FAILED');
            }

            $carryEntry = $carryEntries->first();
            if ((int) $carryEntry->leave_balance_account_id !== (int) $destination->getKey()
                || LeaveAmount::toCents((string) $carryEntry->getRawOriginal('amount')) !== $carryCents
                || $carryEntry->effective_date->toDateString() !== $preview->nextPeriodStart->toDateString()
                || (int) $destination->company_id !== (int) $source->company_id
                || (int) $destination->employee_id !== (int) $source->employee_id
                || (int) $destination->leave_type_id !== (int) $source->leave_type_id
                || $destination->period_start->toDateString() !== $preview->nextPeriodStart->toDateString()
                || $destination->period_end->toDateString() !== $preview->nextPeriodEnd->toDateString()) {
                $this->fail($source, $closedRetry ? 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT' : 'FINAL_ACCOUNTING_VERIFICATION_FAILED');
            }
        }

        $expiryEntry = null;
        if ($expiryCents === 0) {
            if ($expiryEntries->isNotEmpty()) {
                $this->fail($source, 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT');
            }
        } else {
            if ($expiryEntries->count() !== 1) {
                $this->fail($source, $closedRetry ? 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT' : 'FINAL_ACCOUNTING_VERIFICATION_FAILED');
            }

            $expiryEntry = $expiryEntries->first();
            if (LeaveAmount::toCents((string) $expiryEntry->getRawOriginal('amount')) !== -$expiryCents
                || $expiryEntry->effective_date->toDateString() !== $preview->periodEnd->toDateString()) {
                $this->fail($source, $closedRetry ? 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT' : 'FINAL_ACCOUNTING_VERIFICATION_FAILED');
            }
        }

        $expectedSourceBalance = LeaveAmount::toCents($preview->closingBalance) - $expiryCents;
        $actualSourceBalance = LeaveAmount::toCents($this->balanceCalculator->calculate($source, $preview->periodEnd->toDateString())->balance);
        if ($expectedSourceBalance !== $actualSourceBalance) {
            $this->fail($source, $closedRetry ? 'CLOSED_ACCOUNT_ACCOUNTING_INCONSISTENT' : 'FINAL_ACCOUNTING_VERIFICATION_FAILED');
        }

        return [$carryEntry, $expiryEntry];
    }

    private function closingEntries(
        EmployeeLeaveBalanceAccount $source,
        LeaveLedgerTransactionType $type,
        ?int $accountId = null,
    ): Collection {
        return LeaveLedgerEntry::query()
            ->where('company_id', $source->company_id)
            ->where('transaction_type', $type->value)
            ->where('reference_type', LeavePeriodClosingCalculator::CLOSING_REFERENCE_TYPE)
            ->where('reference_id', $source->getKey())
            ->when($accountId !== null, fn ($query) => $query->where('leave_balance_account_id', $accountId))
            ->orderBy('id')
            ->get();
    }

    private function assertEligible(EmployeeLeaveBalanceAccount $source, CalculatedLeavePeriodClosing $preview): void
    {
        if (! $preview->eligibleToClose) {
            $this->fail($source, 'CLOSING_NOT_ELIGIBLE', array_map(
                fn (LeavePeriodClosingBlocker $blocker): string => $blocker->value,
                $preview->blockers,
            ));
        }
    }

    /** @param list<string> $blockers */
    private function fail(EmployeeLeaveBalanceAccount $source, string $reason, array $blockers = []): never
    {
        throw new LeavePeriodFinalizationException((int) $source->getKey(), $reason, $blockers);
    }
}
