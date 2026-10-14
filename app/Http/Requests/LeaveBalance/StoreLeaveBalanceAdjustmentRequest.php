<?php

namespace App\Http\Requests\LeaveBalance;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveBalanceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('adjust', $this->route('leaveBalanceAccount')) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'regex:/^-?(?:0|[1-9]\d{0,7})(?:\.\d{1,2})?$/'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
            'company_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'leave_type_id' => ['prohibited'],
            'leave_policy_id' => ['prohibited'],
            'employee_leave_policy_assignment_id' => ['prohibited'],
            'leave_balance_account_id' => ['prohibited'],
            'transaction_type' => ['prohibited'],
            'created_by' => ['prohibited'],
            'reference_type' => ['prohibited'],
            'reference_id' => ['prohibited'],
        ];
    }
}
