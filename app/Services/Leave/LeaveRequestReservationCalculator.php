<?php

namespace App\Services\Leave;

use App\Enums\LeaveRequestStatus;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class LeaveRequestReservationCalculator
{
    public function pendingCents(EmployeeLeaveBalanceAccount $account, ?int $exceptRequestId = null): int
    {
        return $this->pendingCentsForAccounts(new Collection([$account]), $exceptRequestId)[$account->getKey()] ?? 0;
    }

    /** @return array<int, int> */
    public function pendingCentsForAccounts(Collection $accounts, ?int $exceptRequestId = null): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $totals = LeaveRequest::query()
            ->where(function (Builder $query) use ($accounts): void {
                foreach ($accounts as $account) {
                    $query->orWhere(function (Builder $accountQuery) use ($account): void {
                        $accountQuery->where('company_id', $account->company_id)
                            ->where('employee_id', $account->employee_id)
                            ->where('leave_type_id', $account->leave_type_id)
                            ->where('leave_balance_account_id', $account->getKey());
                    });
                }
            })
            ->where('status', LeaveRequestStatus::Pending->value)
            ->when($exceptRequestId !== null, fn ($query) => $query->where('id', '<>', $exceptRequestId))
            ->selectRaw('leave_balance_account_id, CAST(COALESCE(SUM(requested_days), 0) AS TEXT) AS reserved_days')
            ->groupBy('leave_balance_account_id')->get();

        return $totals->mapWithKeys(fn ($row): array => [(int) $row->leave_balance_account_id => LeaveAmount::toCents((string) $row->reserved_days)])->all();
    }
}
