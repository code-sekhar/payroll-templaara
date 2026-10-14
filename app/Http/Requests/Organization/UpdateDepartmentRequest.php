<?php

namespace App\Http\Requests\Organization;

use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('department.update') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()->id;
        $departmentId = $this->route('department');

        return ['name' => ['required', 'string', 'max:255', Rule::unique('departments')->where('company_id', $companyId)->ignore($departmentId)], 'code' => ['required', 'string', 'max:64', Rule::unique('departments')->where('company_id', $companyId)->ignore($departmentId)], 'description' => ['nullable', 'string', 'max:5000'], 'status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
