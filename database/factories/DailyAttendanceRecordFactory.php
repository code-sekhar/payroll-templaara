<?php

namespace Database\Factories;

use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceRecordState;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAttendanceRecord>
 */
class DailyAttendanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'employee_id' => fn (array $attributes): int => Employee::factory()->create(['company_id' => $attributes['company_id']])->id,
            'attendance_date' => fake()->date(),
            'status' => CalculatedAttendanceStatus::Absent,
            'record_state' => DailyAttendanceRecordState::Final,
            'expected_start_at' => null,
            'expected_end_at' => null,
            'expected_minutes' => 0,
            'first_check_in_at' => null,
            'last_check_out_at' => null,
            'worked_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'is_late' => false,
            'is_early_leave' => false,
            'work_schedule_id' => null,
            'work_schedule_assignment_id' => null,
            'shift_id' => null,
            'holiday_calendar_id' => null,
            'holiday_id' => null,
            'attendance_policy_id' => null,
            'attendance_policy_assignment_id' => null,
            'calculated_at' => now('UTC'),
            'finalized_at' => now('UTC'),
            'calculation_version' => 1,
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    public function present(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::Present,
            'record_state' => DailyAttendanceRecordState::Final,
        ]);
    }

    public function absent(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::Absent,
            'record_state' => DailyAttendanceRecordState::Final,
        ]);
    }

    public function halfDay(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::HalfDay,
            'record_state' => DailyAttendanceRecordState::Final,
        ]);
    }

    public function holiday(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::Holiday,
            'record_state' => DailyAttendanceRecordState::Final,
        ]);
    }

    public function offDay(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::OffDay,
            'record_state' => DailyAttendanceRecordState::Final,
        ]);
    }

    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::Incomplete,
            'record_state' => DailyAttendanceRecordState::NeedsReview,
            'finalized_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => [
            'status' => CalculatedAttendanceStatus::InProgress,
            'record_state' => DailyAttendanceRecordState::Provisional,
            'finalized_at' => null,
        ]);
    }
}
