<?php

namespace Database\Factories;

use App\Enums\AttendancePolicyStatus;
use App\Models\AttendancePolicy;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePolicy>
 */
class AttendancePolicyFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->unique()->bothify('POL-####'),
            'description' => fake()->optional()->sentence(),
            'status' => AttendancePolicyStatus::Active,
            'grace_in_minutes' => 0,
            'grace_out_minutes' => 0,
            'late_after_minutes' => 0,
            'early_leave_after_minutes' => 0,
            'half_day_before_minutes' => 0,
            'absent_before_minutes' => 0,
            'overtime_enabled' => false,
            'overtime_after_minutes' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => AttendancePolicyStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => AttendancePolicyStatus::Inactive]);
    }

    public function overtimeEnabled(): static
    {
        return $this->state(fn (): array => ['overtime_enabled' => true]);
    }

    public function overtimeDisabled(): static
    {
        return $this->state(fn (): array => ['overtime_enabled' => false]);
    }
}
