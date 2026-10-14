<?php

namespace App\Http\Requests\AttendancePolicy;

use App\Models\AttendancePolicy;

class StoreAttendancePolicyRequest extends AttendancePolicyRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(array_replace([
            'status' => 'active',
            'grace_in_minutes' => 0,
            'grace_out_minutes' => 0,
            'late_after_minutes' => 0,
            'early_leave_after_minutes' => 0,
            'half_day_before_minutes' => 0,
            'absent_before_minutes' => 0,
            'overtime_enabled' => false,
            'overtime_after_minutes' => 0,
            'location_mode' => 'disabled',
            'worksite_ids' => [],
        ], $this->all()));
    }

    public function authorize(): bool
    {
        return ($this->user()?->can('create', AttendancePolicy::class) ?? false)
            && ($this->input('status', 'active') !== 'inactive' || ($this->user()?->can('attendance_policy.deactivate') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return $this->policyRules();
    }
}
