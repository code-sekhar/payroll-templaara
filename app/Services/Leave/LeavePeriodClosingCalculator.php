<?php

namespace App\Services\Leave;

use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeavePeriodClosingBlocker;
use App\Exceptions\Leave\InvalidLeaveAccrualConfigurationException;
use App\Exceptions\Leave\InvalidLeavePeriodClosingConfigurationException;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

final class LeavePeriodClosingCalculator
{
    public const CLOSING_REFERENCE_TYPE = 'leave_period_closing';

    public function __construct(
        private LeaveBalanceCalculator $balanceCalculator,
        private LeaveAccrualCalculator $accrualCalculator,
        private LeaveRequestReservationCalculator $reservationCalculator,
    ) {}

    public function calculate(EmployeeLeaveBalanceAccount $balanceAccount, CarbonInterface $asOfDate): CalculatedLeavePeriodClosing
    {
        return $this->calculateInternal($balanceAccount, $asOfDate, false);
    }

    /**
     * Rebuilds a closed account's historical preview for finalization retry verification.
     * Normal previews continue to report ACCOUNT_ALREADY_CLOSED.
     */
    public function calculateForFinalizationRetry(EmployeeLeaveBalanceAccount $balanceAccount, CarbonInterface $asOfDate): CalculatedLeavePeriodClosing
    {
        return $this->calculateInternal($balanceAccount, $asOfDate, true);
    }

    private function calculateInternal(
        EmployeeLeaveBalanceAccount $balanceAccount,
        CarbonInterface $asOfDate,
        bool $allowClosedAccountVerification,
    ): CalculatedLeavePeriodClosing {
        $balanceAccount->loadMissing([
            'employee.company',
            'leaveType',
            'leavePolicy',
            'assignment',
        ]);

        $employee = $balanceAccount->employee;
        $policy = $balanceAccount->leavePolicy;
        $assignment = $balanceAccount->assignment;
        $leaveType = $balanceAccount->leaveType;

        if (! $employee || ! $employee->company || ! $policy || ! $assignment || ! $leaveType) {
            $this->invalid($balanceAccount, 'ACCOUNT_REFERENCE_MISSING');
        }

        $timezone = $employee->company->timezone ?: config('app.timezone', 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            $this->invalid($balanceAccount, 'INVALID_COMPANY_TIMEZONE');
        }

        $periodStart = CarbonImmutable::parse($balanceAccount->period_start->toDateString(), $timezone)->startOfDay();
        $periodEnd = CarbonImmutable::parse($balanceAccount->period_end->toDateString(), $timezone)->startOfDay();
        $localAsOfDate = CarbonImmutable::parse($asOfDate->toDateString(), $timezone)->startOfDay();

        if ($periodEnd->lt($periodStart)) {
            $this->invalid($balanceAccount, 'INVALID_ACCOUNT_PERIOD');
        }

        if ((int) $employee->company_id !== (int) $balanceAccount->company_id
            || (int) $employee->getKey() !== (int) $balanceAccount->employee_id
            || (int) $leaveType->company_id !== (int) $balanceAccount->company_id
            || (int) $leaveType->getKey() !== (int) $balanceAccount->leave_type_id
            || (int) $policy->company_id !== (int) $balanceAccount->company_id
            || (int) $policy->getKey() !== (int) $balanceAccount->leave_policy_id
            || (int) $policy->leave_type_id !== (int) $leaveType->getKey()
            || (int) $assignment->company_id !== (int) $balanceAccount->company_id
            || (int) $assignment->employee_id !== (int) $employee->getKey()
            || (int) $assignment->leave_type_id !== (int) $leaveType->getKey()
            || (int) $assignment->leave_policy_id !== (int) $policy->getKey()
            || (int) $assignment->getKey() !== (int) $balanceAccount->employee_leave_policy_assignment_id) {
            $this->invalid($balanceAccount, 'POLICY_ASSIGNMENT_MISMATCH');
        }

        try {
            $carryForwardEnabled = (bool) $policy->carry_forward_enabled;
            $rawCarryForwardLimit = $policy->getRawOriginal('max_carry_forward_days');
            $carryForwardLimitCents = $rawCarryForwardLimit === null ? null : LeaveAmount::toCents((string) $rawCarryForwardLimit);
        } catch (InvalidArgumentException) {
            $this->invalid($balanceAccount, 'INVALID_CARRY_FORWARD_LIMIT');
        }

        if (($carryForwardEnabled && $carryForwardLimitCents === null)
            || (! $carryForwardEnabled && $carryForwardLimitCents !== null)
            || ($carryForwardLimitCents !== null && $carryForwardLimitCents < 0)) {
            $this->invalid($balanceAccount, 'INVALID_CARRY_FORWARD_CONFIGURATION');
        }

        $balance = $this->balanceCalculator->calculate($balanceAccount, $periodEnd->toDateString())->balance;
        $closingBalanceCents = LeaveAmount::toCents($balance) - $this->priorClosingExpiryCents($balanceAccount, $periodEnd->toDateString());
        $postedAccrualCents = $this->postedAccrualCents($balanceAccount);
        $pendingReservationCents = $this->reservationCalculator->pendingCents($balanceAccount);

        if ($pendingReservationCents < 0) {
            $this->invalid($balanceAccount, 'INVALID_PENDING_RESERVATION_AMOUNT');
        }

        $blockers = [];
        if ($localAsOfDate->lt($periodEnd)) {
            $blockers[] = LeavePeriodClosingBlocker::PeriodNotEnded;
        }

        $finalAccrualTarget = null;
        $missingAccrualAmount = null;

        try {
            $finalAccrualTarget = $this->accrualCalculator->calculate($balanceAccount, $periodEnd)->accruedAmount;
            $targetAccrualCents = LeaveAmount::toCents($finalAccrualTarget);
            if ($postedAccrualCents > $targetAccrualCents) {
                $blockers[] = LeavePeriodClosingBlocker::PostedAccrualExceedsCalculatedTarget;
                $missingAccrualAmount = '0.00';
            } else {
                $missingCents = $targetAccrualCents - $postedAccrualCents;
                $missingAccrualAmount = LeaveAmount::fromCents($missingCents);
                if ($missingCents > 0) {
                    $blockers[] = LeavePeriodClosingBlocker::AccrualNotFullyReconciled;
                }
            }
        } catch (InvalidLeaveAccrualConfigurationException $exception) {
            if ($exception->reason !== 'UNSUPPORTED_ACCRUAL_FREQUENCY'
                && $exception->reason !== 'UNSUPPORTED_ACCRUAL_CONFIGURATION') {
                throw $exception;
            }

            $blockers[] = LeavePeriodClosingBlocker::UnsupportedAccrualConfiguration;
        }

        if ($closingBalanceCents < 0) {
            $carryForwardCents = 0;
            $expireCents = 0;
            $blockers[] = LeavePeriodClosingBlocker::NegativeClosingBalance;
        } else {
            $carryForwardCents = $carryForwardEnabled
                ? min($closingBalanceCents, $carryForwardLimitCents ?? 0)
                : 0;
            $expireCents = $closingBalanceCents - $carryForwardCents;
        }

        if ($pendingReservationCents > 0) {
            $blockers[] = LeavePeriodClosingBlocker::PendingLeaveReservations;
        }

        $alreadyClosed = $balanceAccount->status === LeaveBalanceAccountStatus::Closed;
        if ($alreadyClosed && ! $allowClosedAccountVerification) {
            $blockers[] = LeavePeriodClosingBlocker::AccountAlreadyClosed;
        }

        [$nextPeriodStart, $nextPeriodEnd] = $this->nextPeriod($periodStart, $periodEnd);
        [$nextAccount, $hasNextPeriodConflict] = $this->nextAccount($balanceAccount, $nextPeriodStart, $nextPeriodEnd);
        if ($hasNextPeriodConflict) {
            $blockers[] = LeavePeriodClosingBlocker::NextPeriodAccountConflict;
        }

        return new CalculatedLeavePeriodClosing(
            balanceAccount: $balanceAccount,
            policy: $policy,
            assignment: $assignment,
            asOfDate: $localAsOfDate,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            closingBalance: LeaveAmount::fromCents($closingBalanceCents),
            negativeClosingBalance: LeaveAmount::fromCents(min(0, $closingBalanceCents)),
            finalAccrualTarget: $finalAccrualTarget,
            postedAccrualAmount: LeaveAmount::fromCents($postedAccrualCents),
            missingAccrualAmount: $missingAccrualAmount,
            carryForwardEnabled: $carryForwardEnabled,
            carryForwardLimit: $carryForwardLimitCents === null ? null : LeaveAmount::fromCents($carryForwardLimitCents),
            carryForwardAmount: LeaveAmount::fromCents($carryForwardCents),
            expireAmount: LeaveAmount::fromCents($expireCents),
            pendingReservationAmount: LeaveAmount::fromCents($pendingReservationCents),
            nextPeriodStart: $nextPeriodStart,
            nextPeriodEnd: $nextPeriodEnd,
            nextAccount: $nextAccount,
            nextAccountExists: $nextAccount !== null,
            alreadyClosed: $alreadyClosed,
            eligibleToClose: $blockers === [],
            blockers: $blockers,
        );
    }

    private function postedAccrualCents(EmployeeLeaveBalanceAccount $balanceAccount): int
    {
        $entries = LeaveLedgerEntry::query()
            ->where('company_id', $balanceAccount->company_id)
            ->where('leave_balance_account_id', $balanceAccount->getKey())
            ->where('transaction_type', LeaveLedgerTransactionType::Accrual->value)
            ->whereDate('effective_date', '<=', $balanceAccount->period_end->toDateString())
            ->get(['amount']);

        $total = 0;
        foreach ($entries as $entry) {
            try {
                $amountCents = LeaveAmount::toCents((string) $entry->getRawOriginal('amount'));
            } catch (InvalidArgumentException) {
                $this->invalid($balanceAccount, 'INVALID_POSTED_ACCRUAL_AMOUNT');
            }

            if ($amountCents <= 0) {
                $this->invalid($balanceAccount, 'INVALID_POSTED_ACCRUAL_AMOUNT');
            }

            $total += $amountCents;
        }

        return $total;
    }

    private function priorClosingExpiryCents(EmployeeLeaveBalanceAccount $balanceAccount, string $periodEnd): int
    {
        $entries = LeaveLedgerEntry::query()
            ->where('company_id', $balanceAccount->company_id)
            ->where('leave_balance_account_id', $balanceAccount->getKey())
            ->where('transaction_type', LeaveLedgerTransactionType::Expiry->value)
            ->where('reference_type', self::CLOSING_REFERENCE_TYPE)
            ->where('reference_id', $balanceAccount->getKey())
            ->whereDate('effective_date', '<=', $periodEnd)
            ->get(['amount']);

        $totalCents = 0;
        foreach ($entries as $entry) {
            try {
                $amountCents = LeaveAmount::toCents((string) $entry->getRawOriginal('amount'));
            } catch (InvalidArgumentException) {
                $this->invalid($balanceAccount, 'INVALID_PRIOR_CLOSING_EXPIRY_AMOUNT');
            }

            if ($amountCents >= 0) {
                $this->invalid($balanceAccount, 'INVALID_PRIOR_CLOSING_EXPIRY_AMOUNT');
            }

            // Expiry changes the live ledger balance, but the preview must retain the pre-disposition closing amount.
            $totalCents += $amountCents;
        }

        return $totalCents;
    }

    /**
     * Reapply the inclusive account-period span to the next start using calendar units.
     * No-overflow arithmetic clamps dates such as a February 29 anniversary to February 28.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function nextPeriod(CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
    {
        $nextStart = $periodEnd->addDay()->startOfDay();
        $periodLength = $periodStart->diff($periodEnd->addDay());
        $nextEnd = $nextStart
            ->addYearsNoOverflow($periodLength->y)
            ->addMonthsNoOverflow($periodLength->m)
            ->addDays($periodLength->d)
            ->subDay()
            ->startOfDay();

        return [$nextStart, $nextEnd];
    }

    /** @return array{?EmployeeLeaveBalanceAccount, bool} */
    private function nextAccount(EmployeeLeaveBalanceAccount $balanceAccount, CarbonImmutable $nextStart, CarbonImmutable $nextEnd): array
    {
        $overlappingAccounts = EmployeeLeaveBalanceAccount::query()
            ->where('company_id', $balanceAccount->company_id)
            ->where('employee_id', $balanceAccount->employee_id)
            ->where('leave_type_id', $balanceAccount->leave_type_id)
            ->whereKeyNot($balanceAccount->getKey())
            ->whereDate('period_start', '<=', $nextEnd->toDateString())
            ->whereDate('period_end', '>=', $nextStart->toDateString())
            ->orderBy('id')
            ->get();

        $exactAccounts = $overlappingAccounts->filter(fn (EmployeeLeaveBalanceAccount $account): bool => $account->period_start->toDateString() === $nextStart->toDateString()
            && $account->period_end->toDateString() === $nextEnd->toDateString());

        $nextAccount = $exactAccounts->count() === 1 ? $exactAccounts->first() : null;
        $conflict = $overlappingAccounts->count() > 1 || ($overlappingAccounts->isNotEmpty() && $nextAccount === null);

        return [$nextAccount, $conflict];
    }

    private function invalid(EmployeeLeaveBalanceAccount $account, string $reason): never
    {
        throw new InvalidLeavePeriodClosingConfigurationException(
            $account->getKey() ? (int) $account->getKey() : null,
            $reason,
        );
    }
}
