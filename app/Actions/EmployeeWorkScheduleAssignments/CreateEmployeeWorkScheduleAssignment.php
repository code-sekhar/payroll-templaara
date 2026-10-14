<?php

namespace App\Actions\EmployeeWorkScheduleAssignments;

use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Enums\WorkScheduleStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeeWorkScheduleAssignment
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $actor, array $attributes): EmployeeWorkScheduleAssignment
    {
        return DB::transaction(function () use ($company, $actor, $attributes): EmployeeWorkScheduleAssignment {
            $employee = Employee::query()->where('company_id', $company->id)
                ->whereKey($attributes['employee_id'])->lockForUpdate()->first();

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $schedule = WorkSchedule::query()->where('company_id', $company->id)
                ->whereKey($attributes['work_schedule_id'])->sharedLock()->first();

            if (! $schedule || $schedule->status !== WorkScheduleStatus::Active) {
                throw ValidationException::withMessages(['work_schedule_id' => 'Select an active work schedule in this company.']);
            }

            $this->ensureNoOverlap($employee, $attributes);

            return $company->employeeWorkScheduleAssignments()->create([
                'employee_id' => $employee->id,
                'work_schedule_id' => $schedule->id,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function ensureNoOverlap(Employee $employee, array $attributes, ?int $exceptAssignmentId = null): void
    {
        $effectiveFrom = CarbonImmutable::parse($attributes['effective_from'])->toDateString();
        $effectiveTo = filled($attributes['effective_to'] ?? null)
            ? CarbonImmutable::parse($attributes['effective_to'])->toDateString()
            : null;

        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be earlier than the start date.']);
        }

        if ($attributes['status'] !== EmployeeWorkScheduleAssignmentStatus::Active->value) {
            return;
        }

        $overlaps = $employee->workScheduleAssignments()
            ->where('status', EmployeeWorkScheduleAssignmentStatus::Active->value)
            ->overlappingPeriod($effectiveFrom, $effectiveTo)
            ->when($exceptAssignmentId !== null, fn ($query) => $query->where('id', '!=', $exceptAssignmentId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'effective_from' => 'This employee already has an active work schedule assignment during the selected period. End the current assignment before creating another overlapping period.',
            ]);
        }
    }
}
