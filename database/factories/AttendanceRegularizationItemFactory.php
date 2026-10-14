<?php

namespace Database\Factories;

use App\Enums\AttendancePunchType;
use App\Enums\AttendanceRegularizationItemAction;
use App\Models\AttendanceRegularizationItem;
use App\Models\AttendanceRegularizationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceRegularizationItem> */
class AttendanceRegularizationItemFactory extends Factory
{
    public function definition(): array
    {
        $request = AttendanceRegularizationRequest::factory()->create();

        return [
            'company_id' => $request->company_id,
            'employee_id' => $request->employee_id,
            'attendance_regularization_request_id' => $request->id,
            'action' => AttendanceRegularizationItemAction::Add,
            'attendance_punch_id' => null,
            'punch_type' => AttendancePunchType::CheckIn,
            'requested_punched_at' => now('UTC'),
            'timezone' => 'UTC',
            'notes' => null,
        ];
    }

    public function replace(int $punchId): static
    {
        return $this->state(fn (): array => [
            'action' => AttendanceRegularizationItemAction::Replace,
            'attendance_punch_id' => $punchId,
        ]);
    }

    public function remove(int $punchId): static
    {
        return $this->state(fn (): array => [
            'action' => AttendanceRegularizationItemAction::Remove,
            'attendance_punch_id' => $punchId,
            'punch_type' => null,
            'requested_punched_at' => null,
            'timezone' => null,
        ]);
    }
}
