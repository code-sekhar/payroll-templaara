<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmployeeLeaveWithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave_request.self_cancel') ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\\S/'],
            'employee_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'reviewer_id' => ['prohibited'],
            'status' => ['prohibited'],
            'ledger_id' => ['prohibited'],
            'balance_account_id' => ['prohibited'],
            'requested_days' => ['prohibited'],
            'approved_days' => ['prohibited'],
            'requested_by' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'start_date' => ['prohibited'],
            'end_date' => ['prohibited'],
            'leave_type_id' => ['prohibited'],
            'leave_policy_id' => ['prohibited'],
            'employee_leave_policy_assignment_id' => ['prohibited'],
            'leave_balance_account_id' => ['prohibited'],
        ];
    }
}
