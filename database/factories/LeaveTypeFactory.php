<?php

namespace Database\Factories;

use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use App\Models\Company;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
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
            'code' => strtoupper(fake()->unique()->bothify('LT-####')),
            'description' => fake()->optional()->sentence(),
            'category' => LeaveTypeCategory::Paid,
            'is_paid' => true,
            'allows_half_day' => false,
            'status' => LeaveTypeStatus::Active,
            'sort_order' => 0,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => LeaveTypeStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => LeaveTypeStatus::Inactive]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => ['category' => LeaveTypeCategory::Paid, 'is_paid' => true]);
    }

    public function unpaid(): static
    {
        return $this->state(fn (): array => ['category' => LeaveTypeCategory::Unpaid, 'is_paid' => false]);
    }

    public function halfDayAllowed(): static
    {
        return $this->state(fn (): array => ['allows_half_day' => true]);
    }
}
