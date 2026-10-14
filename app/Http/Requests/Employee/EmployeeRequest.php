<?php

namespace App\Http\Requests\Employee;

use App\Enums\EmploymentStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class EmployeeRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    protected function employeeRules(): array
    {
        $companyId = app(ActiveCompany::class)->current()->id;
        $managerRule = Rule::exists('employees', 'id')->where('company_id', $companyId);

        return [
            'user_id' => ['prohibited'],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', 'max:32'],
            'marital_status' => ['nullable', 'string', 'max:32'],
            'personal_email' => ['nullable', 'email', 'max:255'],
            'personal_phone' => ['nullable', 'string', 'max:32'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'designation_id' => ['nullable', 'integer', Rule::exists('designations', 'id')->where('company_id', $companyId)],
            'manager_id' => ['nullable', 'integer', $managerRule],
            'joining_date' => ['required', 'date'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'employment_status' => ['required', Rule::enum(EmploymentStatus::class)],
            'address' => ['nullable', 'string', 'max:5000'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:32'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $employeeId = $this->route('employee') ? (int) $this->route('employee') : null;
            $managerId = $this->input('manager_id');

            if (! $managerId || $employeeId === null) {
                if ($employeeId !== null && (int) $managerId === $employeeId) {
                    $validator->errors()->add('manager_id', 'An employee cannot manage themselves.');
                }

                return;
            }

            $companyId = app(ActiveCompany::class)->current()->id;
            $visited = [];
            $manager = Employee::where('company_id', $companyId)->find($managerId);

            while ($manager !== null) {
                if ($manager->id === $employeeId || in_array($manager->id, $visited, true)) {
                    $validator->errors()->add('manager_id', 'This manager would create a circular reporting line.');

                    return;
                }

                $visited[] = $manager->id;
                $manager = $manager->manager_id
                    ? Employee::where('company_id', $companyId)->find($manager->manager_id)
                    : null;
            }
        }];
    }
}
