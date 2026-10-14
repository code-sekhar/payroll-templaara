<?php

namespace Database\Factories;

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeavePolicyStatus;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeavePolicy>
 */
class LeavePolicyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'leave_type_id' => LeaveType::factory(),
            'company_id' => fn (array $attributes): int => (int) LeaveType::query()->findOrFail($attributes['leave_type_id'])->company_id,
            'name' => fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('LP-####')),
            'description' => fake()->optional()->sentence(),
            'status' => LeavePolicyStatus::Active,
            'entitlement_days' => '20.00',
            'accrual_method' => LeaveAccrualMethod::None,
            'accrual_frequency' => LeaveAccrualFrequency::None,
            'carry_forward_enabled' => false,
            'max_carry_forward_days' => null,
            'max_balance_days' => null,
            'allow_negative_balance' => false,
            'max_negative_balance_days' => null,
            'minimum_request_days' => '1.00',
            'maximum_request_days' => null,
            'minimum_notice_days' => 0,
            'allows_half_day' => false,
            'requires_reason' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => LeavePolicyStatus::Active]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => LeavePolicyStatus::Inactive]);
    }

    public function upfront(): static
    {
        return $this->state(fn (): array => ['accrual_method' => LeaveAccrualMethod::Upfront, 'accrual_frequency' => LeaveAccrualFrequency::None]);
    }

    public function monthlyAccrual(): static
    {
        return $this->state(fn (): array => ['accrual_method' => LeaveAccrualMethod::Accrual, 'accrual_frequency' => LeaveAccrualFrequency::Monthly]);
    }

    public function carryForward(): static
    {
        return $this->state(fn (): array => ['carry_forward_enabled' => true, 'max_carry_forward_days' => '5.00']);
    }
}
