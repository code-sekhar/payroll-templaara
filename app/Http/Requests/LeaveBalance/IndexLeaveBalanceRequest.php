<?php

namespace App\Http\Requests\LeaveBalance;

use App\Models\EmployeeLeaveBalanceAccount;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLeaveBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EmployeeLeaveBalanceAccount::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'search' => ['nullable', 'string', 'max:120'],
            'leave_type_id' => ['nullable', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $companyId)],
            'leave_policy_id' => ['nullable', 'integer', Rule::exists('leave_policies', 'id')->where('company_id', $companyId)],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
        ];
    }
}
