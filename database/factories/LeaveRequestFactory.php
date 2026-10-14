<?php

namespace Database\Factories;

use App\Enums\LeaveLedgerTransactionType;
use App\Enums\LeaveRequestDayType;
use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\LeaveAmount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_leave_policy_assignment_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::factory()->create([
                'company_id' => $attributes['company_id'], 'effective_from' => '2020-01-01', 'effective_to' => null,
            ])->id,
            'employee_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::findOrFail($attributes['employee_leave_policy_assignment_id'])->employee_id,
            'leave_type_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::findOrFail($attributes['employee_leave_policy_assignment_id'])->leave_type_id,
            'leave_policy_id' => fn (array $attributes): int => EmployeeLeavePolicyAssignment::findOrFail($attributes['employee_leave_policy_assignment_id'])->leave_policy_id,
            'leave_balance_account_id' => fn (array $attributes): int => EmployeeLeaveBalanceAccount::factory()->create([
                'company_id' => $attributes['company_id'], 'employee_id' => $attributes['employee_id'],
                'leave_type_id' => $attributes['leave_type_id'], 'leave_policy_id' => $attributes['leave_policy_id'],
                'employee_leave_policy_assignment_id' => $attributes['employee_leave_policy_assignment_id'],
                'period_start' => '2020-01-01', 'period_end' => '2099-12-31',
            ])->id,
            'start_date' => '2026-01-05', 'end_date' => '2026-01-05', 'requested_days' => '1.00',
            'duration' => 'full_day', 'status' => LeaveRequestStatus::Pending, 'reason' => 'Personal leave',
            'employee_note' => null, 'requested_by' => User::factory(), 'requested_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => LeaveRequestStatus::Pending]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => LeaveRequestStatus::Cancelled, 'cancelled_by' => User::factory(), 'cancelled_at' => now(), 'cancellation_reason' => 'Plans changed']);
    }

    public function approved(?User $reviewer = null, ?string $reviewNote = null): static
    {
        $reviewer ??= User::factory()->create();

        return $this->state(fn (): array => [
            'status' => LeaveRequestStatus::Approved,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ])->afterCreating(function (LeaveRequest $request) use ($reviewer): void {
            if (! $request->days()->exists()) {
                $request->days()->create([
                    'company_id' => $request->company_id,
                    'employee_id' => $request->employee_id,
                    'leave_date' => $request->start_date->toDateString(),
                    'day_type' => $request->requested_days === '0.50' ? LeaveRequestDayType::HalfDay : LeaveRequestDayType::FullDay,
                    'requested_amount' => $request->requested_days,
                ]);
            }

            $request->balanceAccount->ledgerEntries()->create([
                'company_id' => $request->company_id,
                'employee_id' => $request->employee_id,
                'leave_type_id' => $request->leave_type_id,
                'leave_policy_id' => $request->leave_policy_id,
                'employee_leave_policy_assignment_id' => $request->employee_leave_policy_assignment_id,
                'transaction_type' => LeaveLedgerTransactionType::LeaveUsed,
                'amount' => LeaveAmount::fromCents(-LeaveAmount::toCents((string) $request->requested_days)),
                'effective_date' => $request->start_date->toDateString(),
                'description' => "Approved leave request LR-{$request->getKey()}",
                'reference_type' => LeaveRequest::class,
                'reference_id' => $request->getKey(),
                'created_by' => $reviewer->getKey(),
                'created_at' => now(),
            ]);
        });
    }

    public function rejected(?User $reviewer = null, string $reviewNote = 'Request does not meet policy requirements'): static
    {
        $reviewer ??= User::factory()->create();

        return $this->state(fn (): array => [
            'status' => LeaveRequestStatus::Rejected,
            'reviewed_by' => $reviewer->getKey(),
            'reviewed_at' => now(),
            'review_note' => $reviewNote,
        ])->afterCreating(function (LeaveRequest $request): void {
            if (! $request->days()->exists()) {
                $request->days()->create([
                    'company_id' => $request->company_id,
                    'employee_id' => $request->employee_id,
                    'leave_date' => $request->start_date->toDateString(),
                    'day_type' => $request->requested_days === '0.50' ? LeaveRequestDayType::HalfDay : LeaveRequestDayType::FullDay,
                    'requested_amount' => $request->requested_days,
                ]);
            }
        });
    }

    public function halfDay(): static
    {
        return $this->state(fn (): array => ['duration' => 'half_day', 'requested_days' => '0.50']);
    }
}
