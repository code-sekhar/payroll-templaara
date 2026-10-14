<?php

namespace App\Http\Requests\EmployeeAttendancePolicyAssignment;

use App\Enums\AttendancePolicyStatus;
use App\Enums\EmployeeAttendancePolicyAssignmentStatus;
use App\Models\Employee;
use App\Models\EmployeeAttendancePolicyAssignment;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeAttendancePolicyAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('attendancePolicyAssignment')) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;
        /** @var EmployeeAttendancePolicyAssignment|null $assignment */
        $assignment = $this->route('attendancePolicyAssignment');
        $policyRule = Rule::exists('attendance_policies', 'id')->where('company_id', $companyId)
            ->where(function ($query) use ($assignment): void {
                $query->where(function ($query): void {
                    $query->whereNull('deleted_at')->where('status', AttendancePolicyStatus::Active->value);
                });

                if ($assignment) {
                    $query->orWhere('id', $assignment->attendance_policy_id);
                }
            });

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'attendance_policy_id' => ['required', 'integer', $policyRule],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'status' => ['required', Rule::enum(EmployeeAttendancePolicyAssignmentStatus::class)],
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
            if ($validator->errors()->hasAny(['employee_id', 'attendance_policy_id', 'effective_from', 'effective_to', 'status'])
                || $this->input('status') !== EmployeeAttendancePolicyAssignmentStatus::Active->value) {
                return;
            }

            /** @var EmployeeAttendancePolicyAssignment|null $assignment */
            $assignment = $this->route('attendancePolicyAssignment');
            $employee = Employee::query()->where('company_id', app(ActiveCompany::class)->current()?->id)
                ->find($this->integer('employee_id'));

            if ($employee && $employee->attendancePolicyAssignments()
                ->where('status', EmployeeAttendancePolicyAssignmentStatus::Active->value)
                ->overlappingPeriod($this->string('effective_from')->toString(), $this->input('effective_to'))
                ->when($assignment, fn ($query) => $query->where('id', '!=', $assignment->id))->exists()) {
                $validator->errors()->add('effective_from', 'This employee already has an active attendance policy assignment during the selected period.');
            }
        }];
    }
}
