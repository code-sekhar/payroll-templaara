<?php

namespace App\Services\Leave;

use App\Enums\LeaveLedgerTransactionType;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class LeaveBalanceCalculator
{
    public function calculate(EmployeeLeaveBalanceAccount $account, DateTimeInterface|string|null $asOfDate = null): CalculatedLeaveBalance
    {
        return $this->calculateMany(new Collection([$account]), $asOfDate)[$account->getKey()];
    }

    /** @return array<int, CalculatedLeaveBalance> */
    public function calculateMany(Collection $accounts, DateTimeInterface|string|null $asOfDate = null): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $date = $this->dateString($asOfDate);
        $aggregateRows = LeaveLedgerEntry::query()
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
            ->when($date !== null, fn (Builder $query): Builder => $query->whereDate('effective_date', '<=', $date))
            ->selectRaw('leave_balance_account_id, transaction_type, CAST(COALESCE(SUM(amount), 0) AS TEXT) AS total, CAST(COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS TEXT) AS positive_total, CAST(COALESCE(SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END), 0) AS TEXT) AS negative_total')
            ->groupBy('leave_balance_account_id', 'transaction_type')->get();

        $totalsByAccount = [];

        foreach ($aggregateRows as $row) {
            $accountId = (int) $row->leave_balance_account_id;
            $transactionType = (string) $row->getRawOriginal('transaction_type');

            $totalsByAccount[$accountId] ??= [
                'totals' => array_fill_keys(array_map(fn (LeaveLedgerTransactionType $type): string => $type->value, LeaveLedgerTransactionType::cases()), '0.00'),
                'positiveAdjustments' => '0.00',
                'negativeAdjustments' => '0.00',
            ];
            $totalsByAccount[$accountId]['totals'][$transactionType] = LeaveAmount::fromCents(LeaveAmount::toCents((string) $row->total));

            if ($transactionType === LeaveLedgerTransactionType::Adjustment->value) {
                $totalsByAccount[$accountId]['positiveAdjustments'] = LeaveAmount::fromCents(LeaveAmount::toCents((string) $row->positive_total));
                $totalsByAccount[$accountId]['negativeAdjustments'] = LeaveAmount::fromCents(LeaveAmount::toCents((string) $row->negative_total));
            }
        }

        $results = [];
        foreach ($accounts as $account) {
            $aggregate = $totalsByAccount[$account->getKey()] ?? [
                'totals' => array_fill_keys(array_map(fn (LeaveLedgerTransactionType $type): string => $type->value, LeaveLedgerTransactionType::cases()), '0.00'),
                'positiveAdjustments' => '0.00',
                'negativeAdjustments' => '0.00',
            ];
            $totals = $aggregate['totals'];
            $balance = array_sum(array_map(fn (string $amount): int => LeaveAmount::toCents($amount), $totals));

            $results[$account->getKey()] = new CalculatedLeaveBalance(
                account: $account,
                asOfDate: $date,
                openingBalance: $totals[LeaveLedgerTransactionType::OpeningBalance->value],
                entitlement: $totals[LeaveLedgerTransactionType::Entitlement->value],
                accrual: $totals[LeaveLedgerTransactionType::Accrual->value],
                carryForward: $totals[LeaveLedgerTransactionType::CarryForward->value],
                positiveAdjustments: $aggregate['positiveAdjustments'],
                negativeAdjustments: $aggregate['negativeAdjustments'],
                leaveUsed: $totals[LeaveLedgerTransactionType::LeaveUsed->value],
                leaveReversed: $totals[LeaveLedgerTransactionType::LeaveReversed->value],
                expiry: $totals[LeaveLedgerTransactionType::Expiry->value],
                balance: LeaveAmount::fromCents($balance),
                transactionTotals: $totals,
            );
        }

        return $results;
    }

    /** @return list<array{entry: LeaveLedgerEntry, running_balance: string}> */
    public function runningBalanceHistory(EmployeeLeaveBalanceAccount $account, DateTimeInterface|string|null $asOfDate = null): array
    {
        $date = $this->dateString($asOfDate);
        $entries = $account->ledgerEntries()->with('creator:id,name')->when($date !== null, fn (Builder $query): Builder => $query->whereDate('effective_date', '<=', $date))
            ->orderBy('effective_date')->orderBy('created_at')->orderBy('id')->get();
        $runningCents = 0;

        return $entries->map(function (LeaveLedgerEntry $entry) use (&$runningCents): array {
            $runningCents += LeaveAmount::toCents((string) $entry->amount);

            return ['entry' => $entry, 'running_balance' => LeaveAmount::fromCents($runningCents)];
        })->all();
    }

    private function dateString(DateTimeInterface|string|null $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date instanceof DateTimeInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date)->toDateString();
    }
}
