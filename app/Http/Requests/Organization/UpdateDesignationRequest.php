<?php

namespace App\Http\Requests\Organization;

use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDesignationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('designation.update') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()->id;
        $designationId = $this->route('designation');

        return ['name' => ['required', 'string', 'max:255', Rule::unique('designations')->where('company_id', $companyId)->ignore($designationId)], 'code' => ['required', 'string', 'max:64', Rule::unique('designations')->where('company_id', $companyId)->ignore($designationId)], 'description' => ['nullable', 'string', 'max:5000'], 'level' => ['nullable', 'integer', 'min:1', 'max:32767'], 'status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
