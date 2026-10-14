<?php

namespace Database\Factories;

use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
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
            'employee_id' => fn (array $attributes): int => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'punch_type' => AttendancePunchType::CheckIn,
            'punched_at' => now('UTC'),
            'timezone' => 'UTC',
            'source' => AttendancePunchSource::Web,
            'device_identifier' => null,
            'external_reference' => null,
            'latitude' => null,
            'longitude' => null,
            'location_accuracy_meters' => null,
            'ip_address' => null,
            'user_agent' => null,
            'notes' => null,
            'created_by' => null,
        ];
    }

    public function checkIn(): static
    {
        return $this->state(fn (): array => ['punch_type' => AttendancePunchType::CheckIn]);
    }

    public function checkOut(): static
    {
        return $this->state(fn (): array => ['punch_type' => AttendancePunchType::CheckOut]);
    }

    public function web(): static
    {
        return $this->state(fn (): array => ['source' => AttendancePunchSource::Web]);
    }

    public function manual(): static
    {
        return $this->state(fn (): array => ['source' => AttendancePunchSource::Manual, 'notes' => 'Manual entry correction reason']);
    }

    public function biometric(): static
    {
        return $this->state(fn (): array => ['source' => AttendancePunchSource::Biometric]);
    }

    public function imported(): static
    {
        return $this->state(fn (): array => ['source' => AttendancePunchSource::Import]);
    }
}
