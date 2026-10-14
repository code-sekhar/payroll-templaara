<?php

namespace App\Http\Requests;

use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class EmployeeAttendanceCorrectionCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $company = app(ActiveCompany::class)->current();
        $employee = $this->attributes->get('employee_portal.employee');

        return $company instanceof Company && $employee instanceof Employee
            && Gate::allows('createSelf', [RegularizationRequest::class, $company, $employee]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'attendance_date' => ['required', 'string', 'date_format:Y-m-d'],
            'employee_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'month' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $company = app(ActiveCompany::class)->current();
            $employee = $this->attributes->get('employee_portal.employee');
            $date = $this->input('attendance_date');

            if (! $company instanceof Company || ! $employee instanceof Employee || ! is_string($date)
                || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return;
            }

            $today = now($company->timezone ?: config('app.timezone', 'UTC'))->toDateString();
            if ($date > $today) {
                $validator->errors()->add('attendance_date', 'Choose a date that has already occurred.');
            }
            if ($employee->joining_date && $date < $employee->joining_date->toDateString()) {
                $validator->errors()->add('attendance_date', 'Choose a date on or after your employment start date.');
            }
        }];
    }
}
