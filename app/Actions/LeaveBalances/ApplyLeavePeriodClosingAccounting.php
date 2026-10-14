<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePeriodClosingBlocker;
use App\Exceptions\Leave\LeavePeriodClosingAccountingException;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\User;
use App\Services\Leave\CalculatedLeavePeriodClosing;
use App\Services\Leave\LeaveAmount;
use App\Services\Leave\LeavePeriodClosingAccountingResult;
use App\Services\Leave\LeavePeriodClosingCalculator;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ApplyLeavePeriodClosingAccounting
{
    public function __construct(
        private readonly LeavePeriodClosingCalculator $calculator,
        private readonly PostLeaveLedgerEntry $ledgerPoster,
    ) {}

    public function execute(
        EmployeeLeaveBalanceAccount $balanceAccount,
        CarbonInterface $asOfDate,
        ?User $actor = null,
    ): LeavePeriodClosingAccountingResult {
        return DB::transaction(function () use ($balanceAccount, $asOfDate, $actor): LeavePeriodClosingAccountingResult {
            $sourceAccount = EmployeeLeaveBalanceAccount::query()
                ->where('company_id', $balanceAccount->company_id)
                ->whereKey($balanceAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $preview = $this->calculator->calculate($sourceAccount, $asOfDate);
            $this->assertEligible($preview);

            $carryForwardCents = LeaveAmount::toCents($preview->carryForwardAmount);
            $expiryCents = LeaveAmount::toCents($preview->expireAmount);
            $destinationAccount = null;

            // Lock order is always source first, then the exact destination account.
            if ($carryForwardCents > 0) {
                if (! $preview->nextAccount) {
                    $this->fail($sourceAccount, 'NEXT_PERIOD_ACCOUNT_REQUIRED');
                }

                $destinationAccount = EmployeeLeaveBalanceAccount::query()
                    ->where('company_id', $sourceAccount->company_id)
                    ->whereKey($preview->nextAccount->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $destinationAccount) {
                    $this->fail($sourceAccount, 'NEXT_PERIOD_ACCOUNT_INVALID');
                }

                $this->assertDestination($sourceAccount, $destinationAccount, $preview);
            }

            $carryEntries = $this->closingEntries(
                $sourceAccount,
                LeaveLedgerTransactionType::CarryForward,
            );
            $expiryEntries = $this->closingEntries(
                $sourceAccount,
                LeaveLedgerTransactionType::Expiry,
            );

            $existingCarry = $this->matchingEntry(
                $sourceAccount,
                $carryEntries,
                $carryForwardCents,
                $destinationAccount?->getKey(),
                $preview->nextPeriodStart->toDateString(),
                'CARRY_FORWARD_ALREADY_MISMATCHED',
            );
            $existingExpiry = $this->matchingEntry(
                $sourceAccount,
                $expiryEntries,
                $expiryCents,
                $sourceAccount->getKey(),
                $preview->periodEnd->toDateString(),
                'EXPIRY_ALREADY_MISMATCHED',
            );

            $carryEntry = $existingCarry;
            if ($carryForwardCents > 0 && ! $carryEntry) {
                $carryEntry = $this->ledgerPoster->postPeriodClosingCarryForwardOnLockedAccount(
                    $destinationAccount,
                    LeaveAmount::fromCents($carryForwardCents),
                    $preview->nextPeriodStart->toDateString(),
                    $actor,
                    LeavePeriodClosingCalculator::CLOSING_REFERENCE_TYPE,
                    (int) $sourceAccount->getKey(),
                    $preview->periodEnd->toDateString(),
                );
            }

            $expiryEntry = $existingExpiry;
            if ($expiryCents > 0 && ! $expiryEntry) {
                $expiryEntry = $this->ledgerPoster->postPeriodClosingExpiryOnLockedAccount(
                    $sourceAccount,
                    LeaveAmount::fromCents(-$expiryCents),
                    $preview->periodEnd->toDateString(),
                    $actor,
                    LeavePeriodClosingCalculator::CLOSING_REFERENCE_TYPE,
                    (int) $sourceAccount->getKey(),
                );
            }

            $carryExists = $carryEntry !== null;
            $expiryExists = $expiryEntry !== null;
            $wasApplied = ($carryEntry !== null && $existingCarry === null)
                || ($expiryEntry !== null && $existingExpiry === null);

            return new LeavePeriodClosingAccountingResult(
                sourceAccount: $sourceAccount,
                nextAccount: $destinationAccount,
                closingPreview: $preview,
                carryForwardAmount: $preview->carryForwardAmount,
                expiryAmount: $preview->expireAmount,
                carryForwardLedgerEntry: $carryEntry,
                expiryLedgerEntry: $expiryEntry,
                carryForwardPosted: $carryExists,
                expiryPosted: $expiryExists,
                wasApplied: $wasApplied,
                isUpToDate: true,
            );
        }, attempts: 3);
    }

    private function assertEligible(CalculatedLeavePeriodClosing $preview): void
    {
        if (! $preview->eligibleToClose) {
            throw new LeavePeriodClosingAccountingException(
                (int) $preview->balanceAccount->getKey(),
                'CLOSING_NOT_ELIGIBLE',
                array_map(fn (LeavePeriodClosingBlocker $blocker): string => $blocker->value, $preview->blockers),
            );
        }
    }

    private function assertDestination(
        EmployeeLeaveBalanceAccount $source,
        EmployeeLeaveBalanceAccount $destination,
        CalculatedLeavePeriodClosing $preview,
    ): void {
        if ((int) $destination->company_id !== (int) $source->company_id
            || (int) $destination->employee_id !== (int) $source->employee_id
            || (int) $destination->leave_type_id !== (int) $source->leave_type_id
            || $destination->period_start->toDateString() !== $preview->nextPeriodStart->toDateString()
            || $destination->period_end->toDateString() !== $preview->nextPeriodEnd->toDateString()) {
            $this->fail($source, 'NEXT_PERIOD_ACCOUNT_INVALID');
        }

        if ($destination->status !== LeaveBalanceAccountStatus::Open) {
            $this->fail($source, 'NEXT_PERIOD_ACCOUNT_CLOSED');
        }

        $destination->loadMissing(['employee', 'leaveType', 'leavePolicy', 'assignment']);
        $policy = $destination->leavePolicy;
        $assignment = $destination->assignment;
        $employee = $destination->employee;
        $leaveType = $destination->leaveType;

        if (! $policy || $policy->trashed() || ! $assignment || $assignment->trashed() || ! $employee || ! $leaveType || $leaveType->trashed()
            || (int) $employee->company_id !== (int) $destination->company_id
            || (int) $employee->getKey() !== (int) $destination->employee_id
            || (int) $assignment->company_id !== (int) $destination->company_id
            || (int) $assignment->employee_id !== (int) $destination->employee_id
            || (int) $assignment->leave_type_id !== (int) $destination->leave_type_id
            || (int) $assignment->leave_policy_id !== (int) $destination->leave_policy_id
            || (int) $assignment->getKey() !== (int) $destination->employee_leave_policy_assignment_id
            || $assignment->effective_from->toDateString() > $preview->nextPeriodStart->toDateString()
            || ($assignment->effective_to && $assignment->effective_to->toDateString() < $preview->nextPeriodEnd->toDateString())
            || (int) $policy->company_id !== (int) $destination->company_id
            || (int) $policy->leave_type_id !== (int) $destination->leave_type_id
            || (int) $leaveType->company_id !== (int) $destination->company_id
            || (int) $leaveType->getKey() !== (int) $destination->leave_type_id) {
            $this->fail($source, 'NEXT_PERIOD_ACCOUNT_INVALID');
        }
    }

    /**
     * @return Collection<int, LeaveLedgerEntry>
     */
    private function closingEntries(
        EmployeeLeaveBalanceAccount $sourceAccount,
        LeaveLedgerTransactionType $type,
        ?int $accountId = null,
    ): Collection {
        return LeaveLedgerEntry::query()
            ->where('company_id', $sourceAccount->company_id)
            ->where('transaction_type', $type->value)
            ->where('reference_type', LeavePeriodClosingCalculator::CLOSING_REFERENCE_TYPE)
            ->where('reference_id', $sourceAccount->getKey())
            ->when($accountId !== null, fn (Builder $query): Builder => $query->where('leave_balance_account_id', $accountId))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, LeaveLedgerEntry>  $entries
     */
    private function matchingEntry(
        EmployeeLeaveBalanceAccount $sourceAccount,
        Collection $entries,
        int $expectedAmountCents,
        ?int $expectedAccountId,
        string $expectedEffectiveDate,
        string $mismatchReason,
    ): ?LeaveLedgerEntry {
        if ($entries->isEmpty()) {
            return null;
        }

        if ($expectedAmountCents <= 0 || $entries->count() !== 1) {
            $this->fail($sourceAccount, $mismatchReason);
        }

        $entry = $entries->first();
        $amountCents = LeaveAmount::toCents((string) $entry->getRawOriginal('amount'));

        if ((int) $entry->leave_balance_account_id !== $expectedAccountId
            || $entry->effective_date->toDateString() !== $expectedEffectiveDate
            || $amountCents !== ($mismatchReason === 'EXPIRY_ALREADY_MISMATCHED' ? -$expectedAmountCents : $expectedAmountCents)) {
            $this->fail($sourceAccount, $mismatchReason);
        }

        return $entry;
    }

    private function fail(EmployeeLeaveBalanceAccount $account, string $reason): never
    {
        throw new LeavePeriodClosingAccountingException(
            (int) $account->getKey(),
            $reason,
        );
    }
}
