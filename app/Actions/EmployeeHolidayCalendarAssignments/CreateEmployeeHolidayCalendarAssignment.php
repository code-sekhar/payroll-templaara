<?php

namespace App\Actions\EmployeeHolidayCalendarAssignments;

use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Models\HolidayCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeeHolidayCalendarAssignment
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $actor, array $attributes): EmployeeHolidayCalendarAssignment
    {
        return DB::transaction(function () use ($company, $actor, $attributes): EmployeeHolidayCalendarAssignment {
            $employee = Employee::query()->where('company_id', $company->id)
                ->whereKey($attributes['employee_id'])->lockForUpdate()->first();

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
            }

            $calendar = HolidayCalendar::query()->where('company_id', $company->id)
                ->whereKey($attributes['holiday_calendar_id'])->sharedLock()->first();

            if (! $calendar || ($attributes['status'] === EmployeeHolidayCalendarAssignmentStatus::Active->value && $calendar->status !== HolidayCalendarStatus::Active)) {
                throw ValidationException::withMessages(['holiday_calendar_id' => 'Select an active holiday calendar in this company.']);
            }

            $this->ensureNoOverlap($employee, $attributes);

            return $company->employeeHolidayCalendarAssignments()->create([
                'employee_id' => $employee->id,
                'holiday_calendar_id' => $calendar->id,
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
        $from = CarbonImmutable::parse($attributes['effective_from'])->toDateString();
        $to = filled($attributes['effective_to'] ?? null) ? CarbonImmutable::parse($attributes['effective_to'])->toDateString() : null;

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be earlier than the start date.']);
        }

        if ($attributes['status'] !== EmployeeHolidayCalendarAssignmentStatus::Active->value) {
            return;
        }

        $overlaps = $employee->employeeHolidayCalendarAssignments()
            ->where('status', EmployeeHolidayCalendarAssignmentStatus::Active->value)
            ->overlappingPeriod($from, $to)
            ->when($exceptAssignmentId !== null, fn ($query) => $query->where('id', '!=', $exceptAssignmentId))
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['effective_from' => 'This employee already has an active holiday calendar assignment during the selected period. End the current assignment before creating an overlapping period.']);
        }
    }
}
