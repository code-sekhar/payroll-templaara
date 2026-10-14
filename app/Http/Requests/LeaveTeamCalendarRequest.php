<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Services\Leave\LeaveRequestReviewerScope;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveTeamCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $company = app(ActiveCompany::class)->current();
        $canFilterDepartments = $company instanceof Company
            && app(LeaveRequestReviewerScope::class)->canViewCompanyRequests($this->user(), $company);

        return [
            'month' => ['sometimes', 'required', 'string', 'size:7', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D'],
            'date' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'required', Rule::in(['all', 'approved', 'pending'])],
            'leave_type_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('leave_types', 'id')->where('company_id', $company?->getKey()),
            ],
            'department_id' => $canFilterDepartments
                ? ['sometimes', 'nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $company->getKey())]
                : ['prohibited'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
