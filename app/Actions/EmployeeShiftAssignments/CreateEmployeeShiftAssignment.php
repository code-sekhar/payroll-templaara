<?php

namespace App\Actions\EmployeeShiftAssignments;

use App\Enums\EmployeeShiftAssignmentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateEmployeeShiftAssignment
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $actor, array $attributes): EmployeeShiftAssignment
    {
        return DB::transaction(function () use ($company, $actor, $attributes): EmployeeShiftAssignment {
            $employee = Employee::query()->where('company_id', $company->id)
                ->whereKey($attributes['employee_id'])->lockForUpdate()->firstOrFail();
            $shift = Shift::query()->where('company_id', $company->id)
                ->whereKey($attributes['shift_id'])->firstOrFail();

            if ($shift->status->value !== 'active') {
                throw ValidationException::withMessages(['shift_id' => 'Only active shifts can be assigned.']);
            }

            $this->ensureNoOverlap($employee, $attributes);

            return $company->employeeShiftAssignments()->create([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
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
        $effectiveTo = isset($attributes['effective_to']) ? CarbonImmutable::parse($attributes['effective_to'])->toDateString() : null;
        if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw ValidationException::withMessages(['effective_to' => 'The end date cannot be earlier than the start date.']);
        }

        if ($attributes['status'] !== EmployeeShiftAssignmentStatus::Active->value) {
            return;
        }

        $overlap = $employee->shiftAssignments()
            ->where('status', EmployeeShiftAssignmentStatus::Active->value)
            ->overlappingPeriod($effectiveFrom, $effectiveTo)
            ->when($exceptAssignmentId !== null, fn ($query) => $query->where('id', '!=', $exceptAssignmentId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee already has an active shift assignment during the selected period.',
            ]);
        }
    }
}
