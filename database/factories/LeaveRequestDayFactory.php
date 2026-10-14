<?php

namespace Database\Factories;

use App\Enums\LeaveRequestDayType;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequestDay> */
class LeaveRequestDayFactory extends Factory
{
    protected $model = LeaveRequestDay::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'leave_request_id' => LeaveRequest::factory(),
            'company_id' => fn (array $attributes): int => LeaveRequest::findOrFail($attributes['leave_request_id'])->company_id,
            'employee_id' => fn (array $attributes): int => LeaveRequest::findOrFail($attributes['leave_request_id'])->employee_id,
            'leave_date' => fn (array $attributes): string => LeaveRequest::findOrFail($attributes['leave_request_id'])->start_date->toDateString(),
            'day_type' => LeaveRequestDayType::FullDay, 'requested_amount' => '1.00',
            'work_schedule_id' => null, 'work_schedule_assignment_id' => null, 'holiday_calendar_id' => null, 'holiday_id' => null,
        ];
    }

    public function halfDay(): static
    {
        return $this->state(fn (): array => ['day_type' => LeaveRequestDayType::HalfDay, 'requested_amount' => '0.50']);
    }

    public function offDay(): static
    {
        return $this->state(fn (): array => ['day_type' => LeaveRequestDayType::OffDay, 'requested_amount' => '0.00']);
    }

    public function holiday(): static
    {
        return $this->state(fn (): array => ['day_type' => LeaveRequestDayType::Holiday, 'requested_amount' => '0.00']);
    }
}
