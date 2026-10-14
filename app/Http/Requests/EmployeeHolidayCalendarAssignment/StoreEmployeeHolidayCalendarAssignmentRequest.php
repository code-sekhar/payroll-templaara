<?php

namespace App\Http\Requests\EmployeeHolidayCalendarAssignment;

use App\Enums\EmployeeHolidayCalendarAssignmentStatus;
use App\Enums\HolidayCalendarStatus;
use App\Models\Employee;
use App\Models\EmployeeHolidayCalendarAssignment;
use App\Tenancy\ActiveCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeHolidayCalendarAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmployeeHolidayCalendarAssignment::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;
        $calendarRule = Rule::exists('holiday_calendars', 'id')->where('company_id', $companyId)->whereNull('deleted_at');
        if ($this->input('status') === EmployeeHolidayCalendarAssignmentStatus::Active->value) {
            $calendarRule->where('status', HolidayCalendarStatus::Active->value);
        }

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'holiday_calendar_id' => ['required', 'integer', $calendarRule],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::enum(EmployeeHolidayCalendarAssignmentStatus::class)],
            'notes' => ['nullable', 'string', 'max:10000'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['employee_id', 'effective_from', 'effective_to', 'status'])
                || $this->input('status') !== EmployeeHolidayCalendarAssignmentStatus::Active->value) {
                return;
            }

            $employee = Employee::query()->where('company_id', app(ActiveCompany::class)->current()?->id)->find($this->integer('employee_id'));

            if ($employee && $employee->employeeHolidayCalendarAssignments()->where('status', EmployeeHolidayCalendarAssignmentStatus::Active->value)
                ->overlappingPeriod($this->string('effective_from')->toString(), $this->input('effective_to'))->exists()) {
                $validator->errors()->add('effective_from', 'This employee already has an active holiday calendar assignment during the selected period.');
            }
        }];
    }
}
