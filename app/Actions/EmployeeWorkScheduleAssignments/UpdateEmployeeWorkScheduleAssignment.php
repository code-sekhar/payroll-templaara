<?php

namespace App\Actions\EmployeeWorkScheduleAssignments;

use App\Enums\WorkScheduleStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEmployeeWorkScheduleAssignment
{
    public function __construct(private readonly CreateEmployeeWorkScheduleAssignment $createAssignment) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, EmployeeWorkScheduleAssignment $assignment, User $actor, array $attributes): EmployeeWorkScheduleAssignment
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $assignment, $actor, $attributes): EmployeeWorkScheduleAssignment {
            $lockedAssignment = $company->employeeWorkScheduleAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $employeeIds = array_unique([$lockedAssignment->employee_id, (int) $attributes['employee_id']]);
            sort($employeeIds);
            $employees = Employee::query()->where('company_id', $company->id)
                ->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employee = $employees->get((int) $attributes['employee_id']);

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $schedule = WorkSchedule::withTrashed()->where('company_id', $company->id)
                ->whereKey($attributes['work_schedule_id'])->sharedLock()->first();
            $sameSchedule = $schedule && (int) $schedule->id === (int) $lockedAssignment->work_schedule_id;

            if (! $schedule || (! $sameSchedule && ($schedule->trashed() || $schedule->status !== WorkScheduleStatus::Active))) {
                throw ValidationException::withMessages(['work_schedule_id' => 'Select an active work schedule in this company.']);
            }

            if ($schedule->trashed() && (
                (int) $attributes['employee_id'] !== (int) $lockedAssignment->employee_id
                || $attributes['effective_from'] !== $lockedAssignment->effective_from->toDateString()
                || ($attributes['effective_to'] ?? null) !== $lockedAssignment->effective_to?->toDateString()
                || $attributes['status'] !== $lockedAssignment->status->value
            )) {
                throw ValidationException::withMessages(['work_schedule_id' => 'An archived schedule can only be retained while editing assignment notes.']);
            }

            $this->createAssignment->ensureNoOverlap($employee, $attributes, $lockedAssignment->id);

            $lockedAssignment->update([
                'employee_id' => $employee->id,
                'work_schedule_id' => $schedule->id,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            return $lockedAssignment->refresh();
        });
    }
}
