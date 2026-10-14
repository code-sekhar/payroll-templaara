<?php

namespace App\Http\Requests;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveReportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && app(ActiveCompany::class)->current()?->getKey() === $company->getKey()
            && ($this->user()?->can('leave_report.view') ?? false);
    }

    /** @return array<string, array<mixed>|string> */
    public function rules(): array
    {
        $companyId = $this->route('company') instanceof Company ? $this->route('company')->getKey() : 0;

        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_map(fn (LeaveRequestStatus $status): string => $status->value, LeaveRequestStatus::cases()))],
            'leave_type_id' => ['nullable', 'integer', Rule::exists('leave_types', 'id')->where('company_id', $companyId)],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
