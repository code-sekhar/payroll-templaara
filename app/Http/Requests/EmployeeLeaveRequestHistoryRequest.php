<?php

namespace App\Http\Requests;

use App\Enums\LeaveRequestStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeLeaveRequestHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && $this->user()->can('leave_request.self_view')
            && $this->attributes->get('employee_portal.employee') instanceof Employee
            && app(ActiveCompany::class)->current() instanceof Company;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $employee = $this->attributes->get('employee_portal.employee');
        $company = app(ActiveCompany::class)->current();

        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(LeaveRequestStatus::cases(), 'value'))],
            'leave_type_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('leave_requests', 'leave_type_id')
                    ->where('company_id', $company?->getKey())
                    ->where('employee_id', $employee?->getKey()),
            ],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
