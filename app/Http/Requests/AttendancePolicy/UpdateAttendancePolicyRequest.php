<?php

namespace App\Http\Requests\AttendancePolicy;

use App\Models\AttendancePolicy;

class UpdateAttendancePolicyRequest extends AttendancePolicyRequest
{
    protected function prepareForValidation(): void
    {
        $attendancePolicy = $this->route('attendancePolicy');
        if (! $attendancePolicy instanceof AttendancePolicy) {
            return;
        }

        $this->merge([
            'location_mode' => $this->input('location_mode', $attendancePolicy->location_mode->value),
            'worksite_ids' => $this->input('worksite_ids', $attendancePolicy->worksites()->where('attendance_worksites.is_active', true)->pluck('attendance_worksites.id')->all()),
        ]);
    }

    public function authorize(): bool
    {
        $attendancePolicy = $this->route('attendancePolicy');

        if (! $attendancePolicy instanceof AttendancePolicy) {
            return false;
        }

        $status = $this->input('status');
        $statusPermissionValid = ! in_array($status, ['active', 'inactive'], true)
            || $status === $attendancePolicy->status->value
            || ($status === 'active' && ($this->user()?->can('attendance_policy.activate') ?? false))
            || ($status === 'inactive' && ($this->user()?->can('attendance_policy.deactivate') ?? false));

        return ($this->user()?->can('update', $attendancePolicy) ?? false)
            && $statusPermissionValid;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $attendancePolicy = $this->route('attendancePolicy');

        return $this->policyRules($attendancePolicy instanceof AttendancePolicy ? $attendancePolicy : null);
    }
}
