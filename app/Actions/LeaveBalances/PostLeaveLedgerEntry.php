<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Exceptions\Leave\LeaveAccrualReconciliationException;
use App\Exceptions\Leave\LeavePeriodClosingAccountingException;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

class PostLeaveLedgerEntry
{
    /**
     * Append source-period expiry while the caller holds the account lock.
     */
    public function postPeriodClosingExpiryOnLockedAccount(
        EmployeeLeaveBalanceAccount $lockedAccount,
        string $amount,
        string $effectiveDate,
        ?User $actor,
        string $referenceType,
        int $referenceId,
    ): LeaveLedgerEntry {
        $amountCents = LeaveAmount::toCents($amount);
        $this->assertPeriodClosingPosting($lockedAccount, $amountCents, $effectiveDate);

        if ($amountCents >= 0) {
            throw new InvalidArgumentException('Expiry postings must be negative.');
        }

        $policy = $lockedAccount->leavePolicy;
        if (! $policy || $policy->trashed() || (int) $policy->company_id !== (int) $lockedAccount->company_id) {
            throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'SOURCE_POLICY_INVALID');
        }

        $currentBalanceCents = $this->accountBalanceCents($lockedAccount);
        $minimumBalanceCents = 0;
        if ($policy->allow_negative_balance) {
            if ($policy->max_negative_balance_days === null) {
                throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'SOURCE_POLICY_INVALID');
            }

            $minimumBalanceCents = -LeaveAmount::toCents((string) $policy->max_negative_balance_days);
        }

        if ($currentBalanceCents + $amountCents < $minimumBalanceCents) {
            throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'NEGATIVE_BALANCE_FLOOR_EXCEEDED');
        }

        return $lockedAccount->ledgerEntries()->create([
            'company_id' => $lockedAccount->company_id,
            'employee_id' => $lockedAccount->employee_id,
            'leave_type_id' => $lockedAccount->leave_type_id,
            'leave_policy_id' => $lockedAccount->leave_policy_id,
            'employee_leave_policy_assignment_id' => $lockedAccount->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::Expiry,
            'amount' => LeaveAmount::fromCents($amountCents),
            'effective_date' => CarbonImmutable::parse($effectiveDate)->toDateString(),
            'description' => "Leave balance expiry for period ending {$lockedAccount->period_end->toDateString()}",
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Append destination carry-forward while the caller holds the destination account lock.
     */
    public function postPeriodClosingCarryForwardOnLockedAccount(
        EmployeeLeaveBalanceAccount $lockedAccount,
        string $amount,
        string $effectiveDate,
        ?User $actor,
        string $referenceType,
        int $sourceAccountId,
        string $sourcePeriodEnd,
    ): LeaveLedgerEntry {
        $amountCents = LeaveAmount::toCents($amount);
        $this->assertPeriodClosingPosting($lockedAccount, $amountCents, $effectiveDate);

        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Carry-forward postings must be positive.');
        }

        $policy = $lockedAccount->leavePolicy;
        if (! $policy || $policy->trashed() || (int) $policy->company_id !== (int) $lockedAccount->company_id) {
            throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'DESTINATION_POLICY_INVALID');
        }

        $projectedCents = $this->accountBalanceCents($lockedAccount) + $amountCents;
        if ($policy->max_balance_days !== null && $projectedCents > LeaveAmount::toCents((string) $policy->max_balance_days)) {
            throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'BALANCE_CAP_EXCEEDED');
        }

        return $lockedAccount->ledgerEntries()->create([
            'company_id' => $lockedAccount->company_id,
            'employee_id' => $lockedAccount->employee_id,
            'leave_type_id' => $lockedAccount->leave_type_id,
            'leave_policy_id' => $lockedAccount->leave_policy_id,
            'employee_leave_policy_assignment_id' => $lockedAccount->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::CarryForward,
            'amount' => LeaveAmount::fromCents($amountCents),
            'effective_date' => CarbonImmutable::parse($effectiveDate)->toDateString(),
            'description' => "Leave carry forward from period ending {$sourcePeriodEnd}",
            'reference_type' => $referenceType,
            'reference_id' => $sourceAccountId,
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Append an accrual while the caller holds a lock on the balance account.
     * This trusted path is intentionally separate from manual posting.
     */
    public function postAccrualOnLockedAccount(
        EmployeeLeaveBalanceAccount $lockedAccount,
        string $amount,
        string $effectiveDate,
        ?User $actor = null,
        ?string $description = null,
    ): LeaveLedgerEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Accrual postings must run inside the locked reconciliation transaction.');
        }

        $amountCents = LeaveAmount::toCents($amount);

        if ($amountCents <= 0) {
            throw new InvalidArgumentException('Accrual postings must be positive.');
        }

        if ($lockedAccount->status !== LeaveBalanceAccountStatus::Open) {
            throw new LogicException('Accrual postings require an open, locked balance account.');
        }

        $date = CarbonImmutable::parse($effectiveDate)->toDateString();
        if ($date < $lockedAccount->period_start->toDateString() || $date > $lockedAccount->period_end->toDateString()) {
            throw new LogicException('The accrual effective date must be within the balance account period.');
        }

        $policy = $lockedAccount->leavePolicy;
        if (! $policy || $policy->trashed() || (int) $policy->company_id !== (int) $lockedAccount->company_id) {
            throw new LogicException('The balance account policy configuration is unavailable.');
        }

        $currentAmount = $lockedAccount->ledgerEntries()
            ->selectRaw('CAST(COALESCE(SUM(amount), 0) AS TEXT) AS total')
            ->value('total');
        $projectedCents = LeaveAmount::toCents((string) $currentAmount) + $amountCents;

        if ($policy->max_balance_days !== null && $projectedCents > LeaveAmount::toCents((string) $policy->max_balance_days)) {
            throw new LeaveAccrualReconciliationException(
                (int) $lockedAccount->getKey(),
                'BALANCE_CAP_EXCEEDED',
            );
        }

        return $lockedAccount->ledgerEntries()->create([
            'company_id' => $lockedAccount->company_id,
            'employee_id' => $lockedAccount->employee_id,
            'leave_type_id' => $lockedAccount->leave_type_id,
            'leave_policy_id' => $lockedAccount->leave_policy_id,
            'employee_leave_policy_assignment_id' => $lockedAccount->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::Accrual,
            'amount' => LeaveAmount::fromCents($amountCents),
            'effective_date' => $date,
            'description' => filled($description) ? trim($description) : null,
            'reference_type' => null,
            'reference_id' => null,
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    /**
     * Append a trusted cancellation reversal while the caller holds the account lock.
     */
    public function postApprovedLeaveReversalOnLockedAccount(
        EmployeeLeaveBalanceAccount $lockedAccount,
        string $amount,
        string $effectiveDate,
        User $actor,
        int $leaveRequestId,
        LeaveLedgerEntry $originalUsageEntry,
    ): LeaveLedgerEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Approved leave reversals must run inside the locked cancellation transaction.');
        }

        $amountCents = LeaveAmount::toCents($amount);
        if ($amountCents <= 0 || LeaveAmount::toCents((string) $originalUsageEntry->amount) !== -$amountCents) {
            throw new InvalidArgumentException('A leave reversal must be positive and exactly compensate its usage entry.');
        }

        if ($lockedAccount->status !== LeaveBalanceAccountStatus::Open) {
            throw new LogicException('Approved leave reversals require an open, locked balance account.');
        }

        $date = CarbonImmutable::parse($effectiveDate)->toDateString();
        if ($date < $lockedAccount->period_start->toDateString() || $date > $lockedAccount->period_end->toDateString()) {
            throw new LogicException('The reversal effective date must be within the balance account period.');
        }

        $policy = $lockedAccount->leavePolicy;
        if (! $policy || $policy->trashed() || (int) $policy->company_id !== (int) $lockedAccount->company_id) {
            throw new LogicException('The balance account policy configuration is unavailable.');
        }

        $projectedCents = $this->accountBalanceCents($lockedAccount) + $amountCents;
        if ($policy->max_balance_days !== null && $projectedCents > LeaveAmount::toCents((string) $policy->max_balance_days)) {
            throw new ApprovedLeaveCancellationException(
                $leaveRequestId,
                'BALANCE_CAP_EXCEEDED',
            );
        }

        $entry = $lockedAccount->ledgerEntries()->create([
            'company_id' => $lockedAccount->company_id,
            'employee_id' => $lockedAccount->employee_id,
            'leave_type_id' => $lockedAccount->leave_type_id,
            'leave_policy_id' => $lockedAccount->leave_policy_id,
            'employee_leave_policy_assignment_id' => $lockedAccount->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::LeaveReversed,
            'amount' => LeaveAmount::fromCents($amountCents),
            'effective_date' => $date,
            'description' => "Approved leave cancellation for request LR-{$leaveRequestId}",
            'reference_type' => LeaveLedgerEntry::class,
            'reference_id' => $originalUsageEntry->getKey(),
            'created_by' => $actor->getKey(),
            'created_at' => now(),
        ]);

        $lockedAccount->updated_by = $actor->getKey();
        $lockedAccount->save();

        return $entry;
    }

    public function handle(
        EmployeeLeaveBalanceAccount $account,
        User $actor,
        LeaveLedgerTransactionType $type,
        string $amount,
        string $effectiveDate,
        ?string $description = null,
    ): LeaveLedgerEntry {
        if (! in_array($type, [LeaveLedgerTransactionType::OpeningBalance, LeaveLedgerTransactionType::Adjustment], true)) {
            throw ValidationException::withMessages(['transaction_type' => 'This leave ledger transaction type is not available in this phase.']);
        }

        return $this->post($account, $actor, $type, $amount, $effectiveDate, $description);
    }

    public function handleApprovedLeaveUsage(LeaveRequest $request, User $actor): LeaveLedgerEntry
    {
        if ($request->status !== LeaveRequestStatus::Pending || ! $request->leave_balance_account_id) {
            throw ValidationException::withMessages(['request' => 'Only a pending request with a balance account may post leave usage.']);
        }

        $account = EmployeeLeaveBalanceAccount::query()
            ->where('company_id', $request->company_id)
            ->whereKey($request->leave_balance_account_id)
            ->firstOrFail();
        $amount = LeaveAmount::fromCents(-LeaveAmount::toCents((string) $request->requested_days));

        return $this->post(
            $account,
            $actor,
            LeaveLedgerTransactionType::LeaveUsed,
            $amount,
            $request->start_date->toDateString(),
            "Approved leave request LR-{$request->getKey()}",
            LeaveRequest::class,
            (int) $request->getKey(),
        );
    }

    private function post(
        EmployeeLeaveBalanceAccount $account,
        User $actor,
        LeaveLedgerTransactionType $type,
        string $amount,
        string $effectiveDate,
        ?string $description,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): LeaveLedgerEntry {
        $amountCents = LeaveAmount::toCents($amount);
        if ($amountCents === 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be greater than or less than zero.']);
        }
        if ($type === LeaveLedgerTransactionType::LeaveUsed && $amountCents >= 0) {
            throw ValidationException::withMessages(['amount' => 'Leave usage must be a negative ledger amount.']);
        }

        $effectiveDate = CarbonImmutable::parse($effectiveDate)->toDateString();
        $description = filled($description) ? trim($description) : null;
        if ($type === LeaveLedgerTransactionType::Adjustment && mb_strlen($description ?? '') < 5) {
            throw ValidationException::withMessages(['description' => 'Provide an adjustment reason of at least five characters.']);
        }

        return DB::transaction(function () use ($account, $actor, $type, $amountCents, $effectiveDate, $description, $referenceType, $referenceId): LeaveLedgerEntry {
            $lockedAccount = EmployeeLeaveBalanceAccount::query()->where('company_id', $account->company_id)
                ->whereKey($account->id)->lockForUpdate()->firstOrFail();

            if ($lockedAccount->status !== LeaveBalanceAccountStatus::Open) {
                throw ValidationException::withMessages(['account' => 'This balance account is closed and cannot receive postings.']);
            }

            $accountStart = $lockedAccount->period_start->toDateString();
            $accountEnd = $lockedAccount->period_end->toDateString();
            if ($effectiveDate < $accountStart || $effectiveDate > $accountEnd) {
                throw ValidationException::withMessages(['effective_date' => 'The effective date must be within the balance account period.']);
            }

            if ($type === LeaveLedgerTransactionType::OpeningBalance) {
                if ($effectiveDate !== $accountStart) {
                    throw ValidationException::withMessages(['effective_date' => 'An opening balance must take effect on the account period start date.']);
                }

                if ($lockedAccount->ledgerEntries()->where('transaction_type', LeaveLedgerTransactionType::OpeningBalance->value)->exists()) {
                    throw ValidationException::withMessages(['amount' => 'An opening balance has already been posted for this account.']);
                }
            }

            if ($type === LeaveLedgerTransactionType::LeaveUsed && LeaveLedgerEntry::query()
                ->where('company_id', $lockedAccount->company_id)
                ->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->exists()) {
                throw ValidationException::withMessages(['request' => 'Leave usage has already been posted for this request.']);
            }

            $policy = $lockedAccount->leavePolicy;
            if (! $policy || $policy->trashed() || (int) $policy->company_id !== (int) $lockedAccount->company_id) {
                throw ValidationException::withMessages(['account' => 'The balance account policy configuration is unavailable.']);
            }

            $currentAmount = $lockedAccount->ledgerEntries()->selectRaw('CAST(COALESCE(SUM(amount), 0) AS TEXT) AS total')->value('total');
            $projectedCents = LeaveAmount::toCents((string) $currentAmount) + $amountCents;
            $minimumCents = 0;
            if ($policy->allow_negative_balance) {
                if ($policy->max_negative_balance_days === null || LeaveAmount::toCents((string) $policy->max_negative_balance_days) <= 0) {
                    throw ValidationException::withMessages(['amount' => 'The policy has an invalid negative-balance limit.']);
                }
                $minimumCents = -LeaveAmount::toCents((string) $policy->max_negative_balance_days);
            }

            if ($projectedCents < $minimumCents) {
                throw ValidationException::withMessages(['amount' => 'This posting would exceed the policy negative-balance limit.']);
            }

            if ($policy->max_balance_days !== null && $projectedCents > LeaveAmount::toCents((string) $policy->max_balance_days)) {
                throw ValidationException::withMessages(['amount' => 'This posting would exceed the policy maximum balance.']);
            }

            $entry = $lockedAccount->ledgerEntries()->create([
                'company_id' => $lockedAccount->company_id,
                'employee_id' => $lockedAccount->employee_id,
                'leave_type_id' => $lockedAccount->leave_type_id,
                'leave_policy_id' => $lockedAccount->leave_policy_id,
                'employee_leave_policy_assignment_id' => $lockedAccount->employee_leave_policy_assignment_id,
                'transaction_type' => $type,
                'amount' => LeaveAmount::fromCents($amountCents),
                'effective_date' => $effectiveDate,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);

            $lockedAccount->updated_by = $actor->id;
            $lockedAccount->save();

            return $entry;
        }, attempts: 3);
    }

    private function assertPeriodClosingPosting(EmployeeLeaveBalanceAccount $lockedAccount, int $amountCents, string $effectiveDate): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Period closing postings must run inside the locked accounting transaction.');
        }

        if ($amountCents === 0) {
            throw new InvalidArgumentException('Period closing postings must be nonzero.');
        }

        if ($lockedAccount->status !== LeaveBalanceAccountStatus::Open) {
            throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'BALANCE_ACCOUNT_CLOSED');
        }

        $date = CarbonImmutable::parse($effectiveDate)->toDateString();
        if ($date < $lockedAccount->period_start->toDateString() || $date > $lockedAccount->period_end->toDateString()) {
            throw new LeavePeriodClosingAccountingException((int) $lockedAccount->getKey(), 'INVALID_EFFECTIVE_DATE');
        }
    }

    private function accountBalanceCents(EmployeeLeaveBalanceAccount $lockedAccount): int
    {
        $currentAmount = $lockedAccount->ledgerEntries()
            ->selectRaw('CAST(COALESCE(SUM(amount), 0) AS TEXT) AS total')
            ->value('total');

        return LeaveAmount::toCents((string) $currentAmount);
    }
}
