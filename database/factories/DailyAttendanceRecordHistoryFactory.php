<?php

namespace Database\Factories;

use App\Enums\DailyAttendanceHistoryReason;
use App\Models\DailyAttendanceRecord;
use App\Models\DailyAttendanceRecordHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyAttendanceRecordHistory>
 */
class DailyAttendanceRecordHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'daily_attendance_record_id' => DailyAttendanceRecord::factory(),
            'company_id' => fn (array $attributes): int => DailyAttendanceRecord::query()->findOrFail($attributes['daily_attendance_record_id'])->company_id,
            'employee_id' => fn (array $attributes): int => DailyAttendanceRecord::query()->findOrFail($attributes['daily_attendance_record_id'])->employee_id,
            'attendance_date' => fn (array $attributes): string => DailyAttendanceRecord::query()->findOrFail($attributes['daily_attendance_record_id'])->attendance_date->toDateString(),
            'status' => fn (array $attributes) => DailyAttendanceRecord::query()->findOrFail($attributes['daily_attendance_record_id'])->status,
            'record_state' => fn (array $attributes) => DailyAttendanceRecord::query()->findOrFail($attributes['daily_attendance_record_id'])->record_state,
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
            'calculation_version' => 1,
            'calculated_at' => now('UTC'),
            'change_reason' => DailyAttendanceHistoryReason::InitialMaterialization,
            'actor_id' => null,
        ];
    }
}
