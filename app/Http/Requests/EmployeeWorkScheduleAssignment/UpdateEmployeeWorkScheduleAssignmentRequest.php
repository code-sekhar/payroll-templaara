<?php

namespace App\Http\Requests\EmployeeWorkScheduleAssignment;

use App\Enums\EmployeeWorkScheduleAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeWorkScheduleAssignment;
use App\Tenancy\ActiveCompany;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeWorkScheduleAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('employeeWorkScheduleAssignment')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;
        /** @var EmployeeWorkScheduleAssignment|null $assignment */
        $assignment = $this->route('employeeWorkScheduleAssignment');
        $scheduleRule = Rule::exists('work_schedules', 'id')->where('company_id', $companyId)
            ->where(fn ($query) => $query->where(fn ($query) => $query->where('status', 'active')->whereNull('deleted_at'))
                ->orWhere('id', $assignment?->work_schedule_id));

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'work_schedule_id' => ['required', 'integer', $scheduleRule],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::enum(EmployeeWorkScheduleAssignmentStatus::class)],
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
                || $this->input('status') !== EmployeeWorkScheduleAssignmentStatus::Active->value) {
                return;
            }

            /** @var EmployeeWorkScheduleAssignment|null $assignment */
            $assignment = $this->route('employeeWorkScheduleAssignment');
            $employee = Employee::query()->where('company_id', app(ActiveCompany::class)->current()?->id)
                ->find($this->integer('employee_id'));

            if ($employee && $employee->workScheduleAssignments()
                ->where('status', EmployeeWorkScheduleAssignmentStatus::Active->value)
                ->overlappingPeriod($this->string('effective_from')->toString(), $this->input('effective_to'))
                ->when($assignment, fn ($query) => $query->where('id', '!=', $assignment->id))
                ->exists()) {
                $validator->errors()->add('effective_from', 'This employee already has an active work schedule assignment during the selected period.');
            }
        }];
    }
}
