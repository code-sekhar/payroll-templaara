<?php

namespace Database\Factories;

use App\Enums\LeaveLedgerTransactionType;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\LeaveLedgerEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveLedgerEntry>
 */
class LeaveLedgerEntryFactory extends Factory
{
    protected $model = LeaveLedgerEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'leave_balance_account_id' => fn (): int => EmployeeLeaveBalanceAccount::factory()->create()->id,
            'company_id' => fn (array $attributes): int => EmployeeLeaveBalanceAccount::findOrFail($attributes['leave_balance_account_id'])->company_id,
            'employee_id' => fn (array $attributes): int => EmployeeLeaveBalanceAccount::findOrFail($attributes['leave_balance_account_id'])->employee_id,
            'leave_type_id' => fn (array $attributes): int => EmployeeLeaveBalanceAccount::findOrFail($attributes['leave_balance_account_id'])->leave_type_id,
            'leave_policy_id' => fn (array $attributes): int => EmployeeLeaveBalanceAccount::findOrFail($attributes['leave_balance_account_id'])->leave_policy_id,
            'employee_leave_policy_assignment_id' => fn (array $attributes): int => EmployeeLeaveBalanceAccount::findOrFail($attributes['leave_balance_account_id'])->employee_leave_policy_assignment_id,
            'transaction_type' => LeaveLedgerTransactionType::Adjustment,
            'amount' => '1.00',
            'effective_date' => '2026-01-01',
            'description' => 'Factory adjustment entry',
            'reference_type' => null,
            'reference_id' => null,
            'created_by' => null,
            'created_at' => now(),
        ];
    }
}
