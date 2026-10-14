<?php

namespace App\Http\Requests\LeaveType;

use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use App\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLeaveTypeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function authorize(): bool
    {
        $leaveType = $this->route('leaveType');
        if (! $leaveType instanceof LeaveType || ! ($this->user()?->can('update', $leaveType) ?? false)) {
            return false;
        }

        $status = $this->input('status');

        return ! in_array($status, ['active', 'inactive'], true)
            || $status === $leaveType->status->value
            || ($status === 'active' && ($this->user()?->can('leave_type.activate') ?? false))
            || ($status === 'inactive' && ($this->user()?->can('leave_type.deactivate') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $leaveType = $this->route('leaveType');
        $companyId = $leaveType instanceof LeaveType ? $leaveType->company_id : null;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types', 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($leaveType?->id)],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('leave_types', 'code')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($leaveType?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', Rule::enum(LeaveTypeCategory::class)],
            'is_paid' => ['required', 'boolean'],
            'allows_half_day' => ['required', 'boolean'],
            'status' => ['required', Rule::enum(LeaveTypeStatus::class)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $leaveType = $this->route('leaveType');
            if (! $leaveType instanceof LeaveType
                || $validator->errors()->has('allows_half_day')
                || $this->boolean('allows_half_day')) {
                return;
            }

            if ($leaveType->policies()->where('status', LeavePolicyStatus::Active->value)->where('allows_half_day', true)->exists()) {
                $validator->errors()->add('allows_half_day', 'This leave type cannot disable half-day while an active policy uses that option.');
            }
        }];
    }
}
