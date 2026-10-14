<?php

namespace App\Http\Requests\LeavePolicy;

use App\Models\LeavePolicy;

class UpdateLeavePolicyRequest extends LeavePolicyRequest
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
        $leavePolicy = $this->route('leavePolicy');
        if (! $leavePolicy instanceof LeavePolicy || ! ($this->user()?->can('update', $leavePolicy) ?? false)) {
            return false;
        }

        $status = $this->input('status');

        return ! in_array($status, ['active', 'inactive'], true)
            || $status === $leavePolicy->status->value
            || ($status === 'active' && ($this->user()?->can('leave_policy.activate') ?? false))
            || ($status === 'inactive' && ($this->user()?->can('leave_policy.deactivate') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $leavePolicy = $this->route('leavePolicy');

        return $this->policyRules($leavePolicy instanceof LeavePolicy ? $leavePolicy : null);
    }
}
