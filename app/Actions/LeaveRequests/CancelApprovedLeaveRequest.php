<?php

namespace App\Actions\LeaveRequests;

use App\Actions\LeaveBalances\PostLeaveLedgerEntry;
use App\Enums\LeaveBalanceAccountStatus;
use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestStatus;
use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Models\CompanyUser;
use App\Models\Employee;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\ApprovedLeaveCancellationResult;
use App\Services\Leave\LeaveAmount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

final class CancelApprovedLeaveRequest
{
    public function __construct(private readonly PostLeaveLedgerEntry $ledgerPoster) {}

    /**
     * Verify cancellation accounting for downstream read-only synchronization.
     */
    public function verifyCancelled(LeaveRequest $leaveRequest): ApprovedLeaveCancellationResult
    {
        return DB::transaction(function () use ($leaveRequest): ApprovedLeaveCancellationResult {
            $request = LeaveRequest::query()->where('company_id', $leaveRequest->company_id)
                ->whereKey($leaveRequest->getKey())->lockForUpdate()->firstOrFail();

            if ($request->status !== LeaveRequestStatus::Cancelled || ! $request->reviewed_by || ! $request->reviewed_at
                || ! $request->cancelled_by || ! $request->cancelled_at || trim((string) $request->cancellation_reason) === '') {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_CANCELLATION_REQUIRED');
            }

            $employee = Employee::withTrashed()->where('company_id', $request->company_id)
                ->whereKey($request->employee_id)->lockForUpdate()->first();
            $account = EmployeeLeaveBalanceAccount::query()->where('company_id', $request->company_id)
                ->whereKey($request->leave_balance_account_id)->lockForUpdate()->first();

            if (! $employee || ! $account) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_REFERENCE_MISMATCH');
            }

            if ($account->status !== LeaveBalanceAccountStatus::Open) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'LEAVE_BALANCE_PERIOD_CLOSED');
            }

            $this->validateReferences($request, $employee, $account);
            $this->validateRequestDays($request);

            $usageEntries = LeaveLedgerEntry::query()
                ->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)
                ->where('reference_type', LeaveRequest::class)
                ->where('reference_id', $request->getKey())
                ->lockForUpdate()
                ->get();

            if ($usageEntries->isEmpty()) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'CANCELLED_LEAVE_REVERSAL_MISSING');
            }
            if ($usageEntries->count() !== 1) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'MULTIPLE_APPROVED_LEAVE_USAGE_ENTRIES');
            }

            $usage = $usageEntries->first();
            $usageCents = LeaveAmount::toCents((string) $usage->amount);
            if ($usageCents >= 0) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_USAGE_SIGN_INVALID');
            }
            if (-$usageCents !== LeaveAmount::toCents((string) $request->requested_days)) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_USAGE_AMOUNT_MISMATCH');
            }
            $this->validateUsageReferences($request, $account, $usage);

            $reversals = LeaveLedgerEntry::query()
                ->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)
                ->where('reference_type', LeaveLedgerEntry::class)
                ->where('reference_id', $usage->getKey())
                ->lockForUpdate()
                ->get();

            if ($reversals->count() > 1) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'MULTIPLE_LEAVE_REVERSALS');
            }
            $reversal = $reversals->first();
            if (! $reversal) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'CANCELLED_LEAVE_REVERSAL_MISSING');
            }

            $this->validateReversal($request, $account, $usage, $reversal, -$usageCents);

            return new ApprovedLeaveCancellationResult(
                $request->load(['days', 'employee', 'leaveType', 'leavePolicy', 'balanceAccount', 'canceller', 'reviewedBy']),
                $account,
                $usage,
                $reversal,
                LeaveAmount::fromCents(-$usageCents),
                false,
                true,
                true,
            );
        }, attempts: 3);
    }

    public function execute(LeaveRequest $leaveRequest, User $actor, string $reason): ApprovedLeaveCancellationResult
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 2000) {
            throw new ApprovedLeaveCancellationException((int) $leaveRequest->getKey(), 'INVALID_CANCELLATION_REASON');
        }

        return DB::transaction(function () use ($leaveRequest, $actor, $reason): ApprovedLeaveCancellationResult {
            $request = LeaveRequest::query()->where('company_id', $leaveRequest->company_id)
                ->whereKey($leaveRequest->getKey())->lockForUpdate()->firstOrFail();

            $employee = Employee::withTrashed()->where('company_id', $request->company_id)
                ->whereKey($request->employee_id)->lockForUpdate()->first();
            if (! $employee) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'EMPLOYEE_REFERENCE_INVALID');
            }

            $this->authorize($request, $employee, $actor);

            $account = EmployeeLeaveBalanceAccount::query()->where('company_id', $request->company_id)
                ->whereKey($request->leave_balance_account_id)->lockForUpdate()->first();
            if (! $account) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'BALANCE_ACCOUNT_REFERENCE_INVALID');
            }

            $this->validateReferences($request, $employee, $account);
            $this->validateRequestDays($request);

            $usageEntries = LeaveLedgerEntry::query()
                ->where('transaction_type', LeaveLedgerTransactionType::LeaveUsed->value)
                ->where('reference_type', LeaveRequest::class)
                ->where('reference_id', $request->getKey())
                ->lockForUpdate()
                ->get();

            if ($usageEntries->isEmpty()) {
                $reason = $request->status === LeaveRequestStatus::Cancelled
                    ? 'CANCELLED_LEAVE_REVERSAL_MISSING'
                    : 'APPROVED_LEAVE_USAGE_NOT_FOUND';
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), $reason);
            }
            if ($usageEntries->count() !== 1) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'MULTIPLE_APPROVED_LEAVE_USAGE_ENTRIES');
            }

            $usage = $usageEntries->first();
            $usageCents = LeaveAmount::toCents((string) $usage->amount);
            if ($usageCents >= 0) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_USAGE_SIGN_INVALID');
            }
            if (-$usageCents !== LeaveAmount::toCents((string) $request->requested_days)) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_USAGE_AMOUNT_MISMATCH');
            }
            $this->validateUsageReferences($request, $account, $usage);

            $reversals = LeaveLedgerEntry::query()
                ->where('transaction_type', LeaveLedgerTransactionType::LeaveReversed->value)
                ->where('reference_type', LeaveLedgerEntry::class)
                ->where('reference_id', $usage->getKey())
                ->lockForUpdate()
                ->get();

            if ($reversals->count() > 1) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'MULTIPLE_LEAVE_REVERSALS');
            }

            $existingReversal = $reversals->first();
            if ($existingReversal) {
                $this->validateReversal($request, $account, $usage, $existingReversal, -$usageCents);
            }

            if ($request->status === LeaveRequestStatus::Cancelled) {
                if (! $request->reviewed_by || ! $request->reviewed_at || ! $request->cancelled_by || ! $request->cancelled_at
                    || trim((string) $request->cancellation_reason) === '' || ! $existingReversal) {
                    throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'CANCELLED_LEAVE_REVERSAL_MISSING');
                }

                return new ApprovedLeaveCancellationResult(
                    $request->load(['days', 'employee', 'leaveType', 'leavePolicy', 'balanceAccount']),
                    $account,
                    $usage,
                    $existingReversal,
                    LeaveAmount::fromCents(-$usageCents),
                    false,
                    true,
                    true,
                );
            }

            if ($request->status !== LeaveRequestStatus::Approved) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'REQUEST_NOT_APPROVED');
            }
            if ($account->status !== LeaveBalanceAccountStatus::Open) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'LEAVE_BALANCE_PERIOD_CLOSED');
            }

            $reversal = $existingReversal ?? $this->ledgerPoster->postApprovedLeaveReversalOnLockedAccount(
                $account,
                LeaveAmount::fromCents(-$usageCents),
                $usage->effective_date->toDateString(),
                $actor,
                (int) $request->getKey(),
                $usage,
            );

            $request->forceFill([
                'status' => LeaveRequestStatus::Cancelled,
                'cancelled_by' => $actor->getKey(),
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'cancellation_reason' => $reason,
            ])->save();

            return new ApprovedLeaveCancellationResult(
                $request->refresh()->load(['days', 'employee', 'leaveType', 'leavePolicy', 'balanceAccount', 'canceller', 'reviewedBy']),
                $account->refresh(),
                $usage,
                $reversal,
                LeaveAmount::fromCents(-$usageCents),
                true,
                false,
                true,
            );
        }, attempts: 3);
    }

    private function authorize(LeaveRequest $request, Employee $employee, User $actor): void
    {
        $membership = CompanyUser::query()->where('company_id', $request->company_id)
            ->where('user_id', $actor->getKey())->where('status', 'active')->exists();

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        try {
            $registrar->setPermissionsTeamId($request->company_id);
            $canCancel = $actor->can('leave_request.cancel');
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        if (! $membership || ! $canCancel) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'CANCELLATION_NOT_AUTHORIZED');
        }

        if ((int) $request->requested_by === (int) $actor->getKey()
            || (int) $employee->user_id === (int) $actor->getKey()) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'REQUESTER_CANNOT_CANCEL_APPROVED_LEAVE');
        }
    }

    private function validateReferences(LeaveRequest $request, Employee $employee, EmployeeLeaveBalanceAccount $account): void
    {
        $valid = (int) $employee->company_id === (int) $request->company_id
            && (int) $account->company_id === (int) $request->company_id
            && (int) $account->employee_id === (int) $request->employee_id
            && (int) $account->leave_type_id === (int) $request->leave_type_id
            && (int) $account->leave_policy_id === (int) $request->leave_policy_id
            && (int) $account->employee_leave_policy_assignment_id === (int) $request->employee_leave_policy_assignment_id
            && $account->period_start->toDateString() <= $request->start_date->toDateString()
            && $account->period_end->toDateString() >= $request->end_date->toDateString();

        if (! $valid) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_REFERENCE_MISMATCH');
        }
    }

    private function validateRequestDays(LeaveRequest $request): void
    {
        $days = $request->days()->orderBy('leave_date')->orderBy('id')->lockForUpdate()->get();
        $totalCents = 0;
        foreach ($days as $day) {
            if ((int) $day->company_id !== (int) $request->company_id
                || (int) $day->employee_id !== (int) $request->employee_id
                || (int) $day->leave_request_id !== (int) $request->getKey()) {
                throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_DAY_REFERENCE_MISMATCH');
            }
            $totalCents += LeaveAmount::toCents((string) $day->requested_amount);
        }

        if ($totalCents !== LeaveAmount::toCents((string) $request->requested_days)) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_USAGE_AMOUNT_MISMATCH');
        }
    }

    private function validateUsageReferences(LeaveRequest $request, EmployeeLeaveBalanceAccount $account, LeaveLedgerEntry $usage): void
    {
        if ((int) $usage->company_id !== (int) $request->company_id
            || (int) $usage->employee_id !== (int) $request->employee_id
            || (int) $usage->leave_balance_account_id !== (int) $account->getKey()
            || (int) $usage->leave_type_id !== (int) $request->leave_type_id
            || (int) $usage->leave_policy_id !== (int) $request->leave_policy_id
            || (int) $usage->employee_leave_policy_assignment_id !== (int) $request->employee_leave_policy_assignment_id) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_USAGE_REFERENCE_MISMATCH');
        }
    }

    private function validateReversal(
        LeaveRequest $request,
        EmployeeLeaveBalanceAccount $account,
        LeaveLedgerEntry $usage,
        LeaveLedgerEntry $reversal,
        int $expectedCents,
    ): void {
        if (LeaveAmount::toCents((string) $reversal->amount) !== $expectedCents
            || (int) $reversal->company_id !== (int) $request->company_id
            || (int) $reversal->employee_id !== (int) $request->employee_id
            || (int) $reversal->leave_balance_account_id !== (int) $account->getKey()
            || (int) $reversal->leave_type_id !== (int) $request->leave_type_id
            || (int) $reversal->leave_policy_id !== (int) $request->leave_policy_id
            || (int) $reversal->employee_leave_policy_assignment_id !== (int) $request->employee_leave_policy_assignment_id
            || (int) $reversal->reference_id !== (int) $usage->getKey()
            || $reversal->reference_type !== LeaveLedgerEntry::class) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'LEAVE_REVERSAL_AMOUNT_MISMATCH');
        }
    }
}
