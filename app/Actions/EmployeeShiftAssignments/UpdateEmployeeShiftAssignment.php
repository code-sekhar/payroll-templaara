<?php

namespace App\Actions\EmployeeShiftAssignments;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEmployeeShiftAssignment
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, EmployeeShiftAssignment $assignment, User $actor, array $attributes): EmployeeShiftAssignment
    {
        abort_unless($assignment->company_id === $company->id, 404);

        return DB::transaction(function () use ($company, $assignment, $actor, $attributes): EmployeeShiftAssignment {
            $employeeIds = array_unique([$assignment->employee_id, (int) $attributes['employee_id']]);
            sort($employeeIds);
            $employees = Employee::query()->where('company_id', $company->id)
                ->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $employee = $employees->get((int) $attributes['employee_id']);

            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'Select an employee in the active company.']);
            }

            $shift = Shift::query()->where('company_id', $company->id)
                ->whereKey($attributes['shift_id'])->firstOrFail();
            if ($shift->status->value !== 'active' && $shift->id !== $assignment->shift_id) {
                throw ValidationException::withMessages(['shift_id' => 'Only active shifts can be assigned.']);
            }

            app(CreateEmployeeShiftAssignment::class)->ensureNoOverlap($employee, $attributes, $assignment->id);

            $assignment->update([
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'status' => $attributes['status'],
                'notes' => $attributes['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);

            return $assignment->refresh();
        });
    }
}
