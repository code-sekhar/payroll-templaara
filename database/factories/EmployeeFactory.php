<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
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
            'employee_code' => strtoupper(fake()->unique()->bothify('EMP-######')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'joining_date' => fake()->date(),
            'employment_type' => 'full_time',
            'employment_status' => 'active',
        ];
    }
}
