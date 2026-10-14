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

class UpdateEmployeeHolidayCalendarAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holidayCalendarAssignment')) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;
        /** @var EmployeeHolidayCalendarAssignment|null $assignment */
        $assignment = $this->route('holidayCalendarAssignment');
        $requireActiveCalendar = $this->input('status') === EmployeeHolidayCalendarAssignmentStatus::Active->value;
        $calendarRule = Rule::exists('holiday_calendars', 'id')->where('company_id', $companyId)
            ->where(function ($query) use ($requireActiveCalendar, $assignment): void {
                $query->where(function ($query) use ($requireActiveCalendar): void {
                    $query->whereNull('deleted_at');
                    if ($requireActiveCalendar) {
                        $query->where('status', HolidayCalendarStatus::Active->value);
                    }
                });
                if ($assignment) {
                    $query->orWhere('id', $assignment->holiday_calendar_id);
                }
            });

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

            /** @var EmployeeHolidayCalendarAssignment|null $assignment */
            $assignment = $this->route('holidayCalendarAssignment');
            $employee = Employee::query()->where('company_id', app(ActiveCompany::class)->current()?->id)->find($this->integer('employee_id'));

            if ($employee && $employee->employeeHolidayCalendarAssignments()->where('status', EmployeeHolidayCalendarAssignmentStatus::Active->value)
                ->overlappingPeriod($this->string('effective_from')->toString(), $this->input('effective_to'))
                ->when($assignment, fn ($query) => $query->where('id', '!=', $assignment->id))->exists()) {
                $validator->errors()->add('effective_from', 'This employee already has an active holiday calendar assignment during the selected period.');
            }
        }];
    }
}
