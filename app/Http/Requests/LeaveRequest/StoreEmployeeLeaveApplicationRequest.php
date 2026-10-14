<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeLeaveApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->attributes->get('employee_portal.employee');
        $company = app(ActiveCompany::class)->current();

        return $employee instanceof Employee
            && $company instanceof Company
            && (int) $employee->company_id === (int) $company->getKey()
            && (bool) $this->user()?->can('createSelf', [LeaveRequest::class, $company, $employee]);
    }

    public function rules(): array
    {
        $company = app(ActiveCompany::class)->current();

        return [
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')
                    ->where('company_id', $company?->getKey())
                    ->where('status', 'active')
                    ->whereNull('deleted_at'),
            ],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', Rule::when($this->input('duration') === 'half_day', ['same:start_date'])],
            'duration' => ['required', Rule::in(['full_day', 'half_day'])],
            'reason' => ['nullable', 'string', 'min:3', 'max:2000'],
            'employee_note' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'requested_by' => ['prohibited'],
            'status' => ['prohibited'],
            'requested_days' => ['prohibited'],
            'working_days' => ['prohibited'],
            'approved_by' => ['prohibited'],
            'rejected_by' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'review_note' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'rejected_at' => ['prohibited'],
            'leave_policy_id' => ['prohibited'],
            'employee_leave_policy_assignment_id' => ['prohibited'],
            'leave_balance_account_id' => ['prohibited'],
            'ledger_entry_id' => ['prohibited'],
            'transaction_type' => ['prohibited'],
            'attendance_status' => ['prohibited'],
            'attendance_record_id' => ['prohibited'],
        ];
    }
}
