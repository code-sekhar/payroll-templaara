<?php

namespace App\Http\Requests\Organization;

use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDesignationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('designation.create') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()->id;

        return ['name' => ['required', 'string', 'max:255', Rule::unique('designations')->where('company_id', $companyId)], 'code' => ['required', 'string', 'max:64', Rule::unique('designations')->where('company_id', $companyId)], 'description' => ['nullable', 'string', 'max:5000'], 'level' => ['nullable', 'integer', 'min:1', 'max:32767'], 'status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
