<?php

namespace App\Http\Requests\AttendancePolicy;

use App\Enums\AttendancePolicyStatus;
use App\Enums\PunchLocationMode;
use App\Models\AttendancePolicy;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class AttendancePolicyRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    protected function policyRules(?AttendancePolicy $attendancePolicy = null): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('attendance_policies', 'name')->where('company_id', $companyId)->ignore($attendancePolicy?->id)],
            'code' => ['required', 'string', 'max:64', Rule::unique('attendance_policies', 'code')->where('company_id', $companyId)->ignore($attendancePolicy?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(AttendancePolicyStatus::class)],
            'grace_in_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'grace_out_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'late_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'early_leave_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'half_day_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'absent_before_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'overtime_enabled' => ['required', 'boolean'],
            'overtime_after_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'location_mode' => ['required', Rule::enum(PunchLocationMode::class)],
            'worksite_ids' => ['sometimes', 'array', 'max:50'],
            'worksite_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('attendance_worksites', 'id')->where('company_id', $companyId)->where('is_active', true)->whereNull('deleted_at'),
            ],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['grace_in_minutes', 'grace_out_minutes', 'late_after_minutes', 'early_leave_after_minutes'])) {
                return;
            }

            if ((int) $this->input('grace_in_minutes') > (int) $this->input('late_after_minutes')) {
                $validator->errors()->add('late_after_minutes', 'The late threshold must be at least the grace-in duration.');
            }

            if ((int) $this->input('grace_out_minutes') > (int) $this->input('early_leave_after_minutes')) {
                $validator->errors()->add('early_leave_after_minutes', 'The early-leave threshold must be at least the grace-out duration.');
            }

            if ($this->input('location_mode') === PunchLocationMode::GeofenceRequired->value
                && ! $validator->errors()->has('worksite_ids')
                && (! is_array($this->input('worksite_ids')) || count($this->input('worksite_ids')) === 0)) {
                $validator->errors()->add('worksite_ids', 'Select at least one active worksite for geofence-required punching.');
            }
        }];
    }
}
