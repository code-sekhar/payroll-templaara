<?php

namespace Database\Factories;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Models\AttendancePunchCorrection;
use App\Models\AttendanceRegularizationItem;
use App\Models\AttendanceRegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendancePunchCorrection>
 */
class AttendancePunchCorrectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $company = Company::factory()->create();
        $employee = Employee::factory()->create(['company_id' => $company->id]);
        $request = AttendanceRegularizationRequest::factory()->approved()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'attendance_date' => '2026-10-05',
        ]);
        $item = AttendanceRegularizationItem::factory()->create([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'attendance_regularization_request_id' => $request->id,
            'action' => AttendanceRegularizationItemAction::Add,
            'punch_type' => AttendancePunchType::CheckOut,
            'requested_punched_at' => '2026-10-05 18:00:00',
            'timezone' => $company->timezone,
        ]);

        return [
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'attendance_date' => $request->attendance_date,
            'attendance_regularization_request_id' => $request->id,
            'attendance_regularization_item_id' => $item->id,
            'action' => AttendanceRegularizationItemAction::Add,
            'original_attendance_punch_id' => null,
            'effective_punch_type' => AttendancePunchType::CheckOut,
            'effective_punched_at' => $item->requested_punched_at,
            'timezone' => $item->timezone,
            'approved_by' => User::factory(),
            'approved_at' => now('UTC'),
            'created_at' => now('UTC'),
        ];
    }
}
