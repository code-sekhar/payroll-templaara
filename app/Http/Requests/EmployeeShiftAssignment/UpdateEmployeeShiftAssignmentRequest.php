<?php

namespace App\Http\Requests\EmployeeShiftAssignment;

use App\Enums\EmployeeShiftAssignmentStatus;
use App\Models\EmployeeShiftAssignment;
use App\Tenancy\ActiveCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeShiftAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('employeeShiftAssignment')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;
        /** @var EmployeeShiftAssignment|null $assignment */
        $assignment = $this->route('employeeShiftAssignment');

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')->where('company_id', $companyId)
                ->where(fn ($query) => $query->where('status', 'active')->orWhere('id', $assignment?->shift_id))],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::enum(EmployeeShiftAssignmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
