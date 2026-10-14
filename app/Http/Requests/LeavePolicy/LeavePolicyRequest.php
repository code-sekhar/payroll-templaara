<?php

namespace App\Http\Requests\LeavePolicy;

use App\Enums\LeaveAccrualFrequency;
use App\Enums\LeaveAccrualMethod;
use App\Enums\LeavePolicyStatus;
use App\Enums\LeaveTypeStatus;
use App\Models\LeavePolicy;
use App\Models\LeaveType;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class LeavePolicyRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    protected function policyRules(?LeavePolicy $leavePolicy = null): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'leave_type_id' => ['required', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_policies', 'name')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($leavePolicy?->id)],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('leave_policies', 'code')->where('company_id', $companyId)->whereNull('deleted_at')->ignore($leavePolicy?->id)],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(LeavePolicyStatus::class)],
            'entitlement_days' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'accrual_method' => ['required', Rule::enum(LeaveAccrualMethod::class)],
            'accrual_frequency' => ['required', Rule::enum(LeaveAccrualFrequency::class)],
            'carry_forward_enabled' => ['required', 'boolean'],
            'max_carry_forward_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'max_balance_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'allow_negative_balance' => ['required', 'boolean'],
            'max_negative_balance_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999.99'],
            'minimum_request_days' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'maximum_request_days' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'minimum_notice_days' => ['required', 'integer', 'min:0', 'max:65535'],
            'allows_half_day' => ['required', 'boolean'],
            'requires_reason' => ['required', 'boolean'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $dependentFields = [
                'leave_type_id', 'status', 'accrual_method', 'accrual_frequency', 'carry_forward_enabled',
                'max_carry_forward_days', 'allow_negative_balance', 'max_negative_balance_days',
                'minimum_request_days', 'maximum_request_days', 'allows_half_day',
            ];
            if ($validator->errors()->hasAny($dependentFields)) {
                return;
            }

            $accrualMethod = LeaveAccrualMethod::from($this->string('accrual_method')->toString());
            $accrualFrequency = LeaveAccrualFrequency::from($this->string('accrual_frequency')->toString());
            if (($accrualMethod === LeaveAccrualMethod::Accrual) === ($accrualFrequency === LeaveAccrualFrequency::None)) {
                $validator->errors()->add('accrual_frequency', 'Accrual policies require a frequency, while other accrual methods require None.');
            }

            if (! $this->boolean('carry_forward_enabled') && $this->input('max_carry_forward_days') !== null) {
                $validator->errors()->add('max_carry_forward_days', 'Maximum carry-forward days must be empty when carry forward is disabled.');
            }

            if ($this->boolean('carry_forward_enabled') && $this->input('max_carry_forward_days') === null) {
                $validator->errors()->add('max_carry_forward_days', 'Set a maximum carry-forward amount when carry forward is enabled.');
            }

            if (! $this->boolean('allow_negative_balance') && $this->input('max_negative_balance_days') !== null) {
                $validator->errors()->add('max_negative_balance_days', 'Maximum negative balance days must be empty when negative balance is disabled.');
            }

            if ($this->boolean('allow_negative_balance') && (float) $this->input('max_negative_balance_days', 0) <= 0) {
                $validator->errors()->add('max_negative_balance_days', 'A positive maximum is required when negative balances are allowed.');
            }

            $minimumRequestDays = (float) $this->input('minimum_request_days');
            $maximumRequestDays = $this->input('maximum_request_days');
            if ($maximumRequestDays !== null && (float) $maximumRequestDays < $minimumRequestDays) {
                $validator->errors()->add('maximum_request_days', 'Maximum request days must be at least the minimum request days.');
            }

            $leaveType = LeaveType::query()
                ->where('company_id', app(ActiveCompany::class)->current()?->id)
                ->find($this->integer('leave_type_id'));

            if ($this->boolean('allows_half_day') && ! $leaveType?->allows_half_day) {
                $validator->errors()->add('allows_half_day', 'This leave type does not allow half-day leave.');
            }

            if (! $this->boolean('allows_half_day') && $minimumRequestDays < 1) {
                $validator->errors()->add('minimum_request_days', 'Minimum request days must be at least 1 when half-day leave is disabled.');
            }

            if ($this->string('status')->toString() === LeavePolicyStatus::Active->value) {
                $currentPolicy = $this->route('leavePolicy');
                $requiresActiveType = ! $currentPolicy instanceof LeavePolicy
                    || $currentPolicy->status !== LeavePolicyStatus::Active
                    || (int) $currentPolicy->leave_type_id !== $leaveType?->id;

                if ($requiresActiveType && $leaveType?->status !== LeaveTypeStatus::Active) {
                    $validator->errors()->add('leave_type_id', 'An active policy must use an active leave type.');
                }
            }
        }];
    }
}
