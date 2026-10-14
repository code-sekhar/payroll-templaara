<?php

namespace Database\Factories;

use App\Enums\EmployeeShiftAssignmentStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeShiftAssignment;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeShiftAssignment>
 */
class EmployeeShiftAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => Employee::factory(),
            'shift_id' => Shift::factory(),
            'effective_from' => fake()->date(),
            'effective_to' => null,
            'status' => EmployeeShiftAssignmentStatus::Active,
            'notes' => null,
        ];
    }
}
