<?php

namespace App\Http\Requests\LeavePolicyAssignment;

use App\Enums\EmployeeLeavePolicyAssignmentStatus;
use App\Models\EmployeeLeavePolicyAssignment;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeLeavePolicyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeLeavePolicyAssignment::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'leave_policy_id' => ['required', 'integer', Rule::exists('leave_policies', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::enum(EmployeeLeavePolicyAssignmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'company_id' => ['prohibited'],
            'leave_type_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
