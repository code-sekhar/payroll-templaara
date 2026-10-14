<?php

namespace App\Http\Requests\LeaveType;

use App\Enums\LeaveTypeCategory;
use App\Enums\LeaveTypeStatus;
use App\Models\LeaveType;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeaveTypeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name', '')),
            'code' => strtoupper(trim((string) $this->input('code', ''))),
            'status' => $this->input('status', 'active'),
            'is_paid' => $this->input('is_paid', false),
            'allows_half_day' => $this->input('allows_half_day', false),
            'sort_order' => $this->input('sort_order', 0),
        ]);
    }

    public function authorize(): bool
    {
        return ($this->user()?->can('create', LeaveType::class) ?? false)
            && ($this->input('status') !== LeaveTypeStatus::Inactive->value || ($this->user()?->can('leave_type.deactivate') ?? false));
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('leave_types', 'name')->where('company_id', $companyId)->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', Rule::unique('leave_types', 'code')->where('company_id', $companyId)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:10000'],
            'category' => ['required', Rule::enum(LeaveTypeCategory::class)],
            'is_paid' => ['required', 'boolean'],
            'allows_half_day' => ['required', 'boolean'],
            'status' => ['required', Rule::enum(LeaveTypeStatus::class)],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
