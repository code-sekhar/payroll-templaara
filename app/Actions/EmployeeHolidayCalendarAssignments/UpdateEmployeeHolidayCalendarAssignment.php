<?php

namespace App\Actions\EmployeeHolidayCalendarAssignments;

use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\HolidayCalendar;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEmployeeHolidayCalendarAssignment
{
    public function __construct(private readonly CreateEmployeeHolidayCalendarAssignment $createAssignment) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, EmployeeHolidayCalendarAssignment $assignment, User $actor, array $attributes): EmployeeHolidayCalendarAssignment
    {
        abort_unless((int) $assignment->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $assignment, $actor, $attributes): EmployeeHolidayCalendarAssignment {
            $lockedAssignment = $company->employeeHolidayCalendarAssignments()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $employeeIds = array_unique([$lockedAssignment->employee_id, (int) $attributes['employee_id']]);
            sort($employeeIds);
            $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $employeeIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employee = $employees->get((int) $attributes['employee_id']);

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $calendar = HolidayCalendar::withTrashed()->where('company_id', $company->id)
                ->whereKey($attributes['holiday_calendar_id'])->sharedLock()->first();
            $sameCalendar = $calendar && (int) $calendar->id === (int) $lockedAssignment->holiday_calendar_id;

            if (! $calendar || (! $sameCalendar && ($calendar->trashed() || ($attributes['status'] === EmployeeHolidayCalendarAssignmentStatus::Active->value && $calendar->status !== HolidayCalendarStatus::Active)))) {
                throw ValidationException::withMessages(['holiday_calendar_id' => 'Select an active holiday calendar in this company.']);
            }

            if ($calendar->trashed() && (
                (int) $attributes['employee_id'] !== (int) $lockedAssignment->employee_id
                || (int) $attributes['holiday_calendar_id'] !== (int) $lockedAssignment->holiday_calendar_id
                || $attributes['effective_from'] !== $lockedAssignment->effective_from->toDateString()
                || ($attributes['effective_to'] ?? null) !== $lockedAssignment->effective_to?->toDateString()
                || $attributes['status'] !== $lockedAssignment->status->value
            )) {
                throw ValidationException::withMessages(['holiday_calendar_id' => 'An archived holiday calendar can only be retained while editing assignment notes.']);
            }

            if ($calendar->status !== HolidayCalendarStatus::Active && (
                ! $sameCalendar
                || (int) $attributes['employee_id'] !== (int) $lockedAssignment->employee_id
                || $attributes['effective_from'] !== $lockedAssignment->effective_from->toDateString()
                || ($attributes['effective_to'] ?? null) !== $lockedAssignment->effective_to?->toDateString()
                || $attributes['status'] !== $lockedAssignment->status->value
            )) {
                throw ValidationException::withMessages(['holiday_calendar_id' => 'An inactive holiday calendar can only be retained while editing assignment notes.']);
            }

            $this->createAssignment->ensureNoOverlap($employee, $attributes, $lockedAssignment->id);

            $lockedAssignment->update([
                'employee_id' => $employee->id,
                'holiday_calendar_id' => $calendar->id,
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
