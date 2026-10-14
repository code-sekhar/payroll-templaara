<?php

namespace App\Http\Requests\LeavePolicy;

use App\Enums\LeavePolicyStatus;
use App\Models\LeavePolicy;

class StoreLeavePolicyRequest extends LeavePolicyRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'code' => strtoupper(trim((string) $this->input('code', ''))),
            'status' => $this->input('status', LeavePolicyStatus::Active->value),
            'entitlement_days' => $this->input('entitlement_days', '0.00'),
            'accrual_method' => $this->input('accrual_method', 'none'),
            'accrual_frequency' => $this->input('accrual_frequency', 'none'),
            'carry_forward_enabled' => $this->input('carry_forward_enabled', false),
            'max_carry_forward_days' => $this->input('max_carry_forward_days'),
            'max_balance_days' => $this->input('max_balance_days'),
            'allow_negative_balance' => $this->input('allow_negative_balance', false),
            'max_negative_balance_days' => $this->input('max_negative_balance_days'),
            'minimum_request_days' => $this->input('minimum_request_days', '1.00'),
            'maximum_request_days' => $this->input('maximum_request_days'),
            'minimum_notice_days' => $this->input('minimum_notice_days', 0),
            'allows_half_day' => $this->input('allows_half_day', false),
            'requires_reason' => $this->input('requires_reason', false),
        ]);
    }

    public function authorize(): bool
    {
        return ($this->user()?->can('create', LeavePolicy::class) ?? false)
            && ($this->input('status') !== LeavePolicyStatus::Inactive->value || ($this->user()?->can('leave_policy.deactivate') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return $this->policyRules();
    }
}
