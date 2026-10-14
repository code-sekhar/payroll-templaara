<?php

namespace App\Http\Requests\LeaveBalance;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveBalanceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeLeaveBalanceAccount::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'employee_leave_policy_assignment_id' => ['required', 'integer', Rule::exists('employee_leave_policy_assignments', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'company_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'leave_type_id' => ['prohibited'],
            'leave_policy_id' => ['prohibited'],
            'status' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
