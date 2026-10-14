<?php

namespace App\Actions\LeaveBalances;

use App\Enums\LeaveBalanceAccountStatus;
use App\Models\Company;
use App\Models\EmployeeLeaveBalanceAccount;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateLeaveBalanceAccount
{
    /** @param array{employee_leave_policy_assignment_id: int, period_start: string, period_end: string} $attributes */
    public function handle(Company $company, User $actor, array $attributes): EmployeeLeaveBalanceAccount
    {
        try {
            return DB::transaction(function () use ($company, $actor, $attributes): EmployeeLeaveBalanceAccount {
                $assignment = EmployeeLeavePolicyAssignment::query()
                    ->where('company_id', $company->id)
                    ->whereKey($attributes['employee_leave_policy_assignment_id'])
                    ->lockForUpdate()->first();

                if (! $assignment) {
                    throw ValidationException::withMessages(['employee_leave_policy_assignment_id' => 'Select a current-company leave policy assignment that has not been archived.']);
                }

                $policy = $assignment->leavePolicy;
                $type = $assignment->leaveType;
                if (! $policy || $policy->trashed() || ! $type || $type->trashed()) {
                    throw ValidationException::withMessages(['employee_leave_policy_assignment_id' => 'The assignment policy or leave type is no longer available.']);
                }

                $periodStart = CarbonImmutable::parse($attributes['period_start'])->toDateString();
                $periodEnd = CarbonImmutable::parse($attributes['period_end'])->toDateString();
                $assignmentStart = $assignment->effective_from->toDateString();
                $assignmentEnd = $assignment->effective_to?->toDateString();

                if ($periodEnd < $periodStart) {
                    throw ValidationException::withMessages(['period_end' => 'The period end must be on or after its start.']);
                }

                if ($periodStart < $assignmentStart || ($assignmentEnd !== null && $periodEnd > $assignmentEnd)) {
                    throw ValidationException::withMessages(['period_start' => 'The balance period must be fully contained within the selected policy assignment.']);
                }

                $duplicate = EmployeeLeaveBalanceAccount::query()
                    ->where('company_id', $company->id)
                    ->where('employee_id', $assignment->employee_id)
                    ->where('leave_type_id', $assignment->leave_type_id)
                    ->where('period_start', $periodStart)
                    ->where('period_end', $periodEnd)->exists();

                if ($duplicate) {
                    throw ValidationException::withMessages(['period_start' => 'A balance account already exists for this employee, leave type, and period.']);
                }

                return $company->leaveBalanceAccounts()->create([
                    'employee_id' => $assignment->employee_id,
                    'leave_type_id' => $assignment->leave_type_id,
                    'leave_policy_id' => $assignment->leave_policy_id,
                    'employee_leave_policy_assignment_id' => $assignment->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'status' => LeaveBalanceAccountStatus::Open,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'elba_logical_period_unique')
                || str_contains($exception->getMessage(), 'employee_leave_balance_accounts.company_id, employee_leave_balance_accounts.employee_id, employee_leave_balance_accounts.leave_type_id, employee_leave_balance_accounts.period_start, employee_leave_balance_accounts.period_end')) {
                throw ValidationException::withMessages(['period_start' => 'A balance account already exists for this employee, leave type, and period.']);
            }

            throw $exception;
        }
    }
}
