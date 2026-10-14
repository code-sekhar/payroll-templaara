<?php

namespace App\Http\Requests\LeavePolicyAssignment;

use App\Models\EmployeeLeavePolicyAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexEmployeeLeavePolicyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EmployeeLeavePolicyAssignment::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'leave_type_id' => ['nullable', 'integer'],
            'leave_policy_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'period' => ['nullable', Rule::in(['current', 'historical', 'future'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}
