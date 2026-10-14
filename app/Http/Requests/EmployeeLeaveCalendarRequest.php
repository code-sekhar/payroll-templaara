<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Employee;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeLeaveCalendarRequest extends FormRequest
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
        return [
            'month' => ['sometimes', 'string', 'size:7', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/D', 'date_format:Y-m'],
        ];
    }
}
