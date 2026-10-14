<?php

namespace Database\Factories;

use App\Enums\AttendanceRegularizationStatus;
use App\Enums\AttendanceRegularizationType;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRegularizationRequest> */
class AttendanceRegularizationRequestFactory extends Factory
{
    public function definition(): array
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);

        return [
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'daily_attendance_record_id' => null,
            'attendance_date' => fake()->dateTimeBetween('-30 days', 'today')->format('Y-m-d'),
            'request_type' => AttendanceRegularizationType::Other,
            'status' => AttendanceRegularizationStatus::Pending,
            'reason' => fake()->sentence(8),
            'employee_note' => null,
            'review_note' => null,
            'requested_by' => User::factory(),
            'requested_at' => now('UTC'),
            'reviewed_by' => null,
            'reviewed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => AttendanceRegularizationStatus::Pending]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => AttendanceRegularizationStatus::Cancelled]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => ['status' => AttendanceRegularizationStatus::Approved]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => ['status' => AttendanceRegularizationStatus::Rejected]);
    }
}
