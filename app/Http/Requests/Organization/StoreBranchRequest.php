<?php

namespace App\Http\Requests\Organization;

use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('branch.create') ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()->id;

        return ['name' => ['required', 'string', 'max:255', Rule::unique('branches')->where('company_id', $companyId)], 'code' => ['required', 'string', 'max:64', Rule::unique('branches')->where('company_id', $companyId)], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'], 'address' => ['nullable', 'string', 'max:5000'], 'city' => ['nullable', 'string', 'max:255'], 'state' => ['nullable', 'string', 'max:255'], 'country' => ['nullable', 'string', 'size:2'], 'postal_code' => ['nullable', 'string', 'max:20'], 'timezone' => ['required', 'timezone'], 'status' => ['required', Rule::in(['active', 'inactive'])]];
    }
}
