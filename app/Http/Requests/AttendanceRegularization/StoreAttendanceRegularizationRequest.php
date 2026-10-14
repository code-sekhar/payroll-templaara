<?php

namespace App\Http\Requests\AttendanceRegularization;

use App\Enums\AttendanceRegularizationItemAction;
use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreAttendanceRegularizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user()?->can('createForCompany', [RegularizationRequest::class, $company]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'company_id' => ['prohibited'],
            'status' => ['prohibited'],
            'requested_by' => ['prohibited'],
            'requested_at' => ['prohibited'],
            'daily_attendance_record_id' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_note' => ['prohibited'],
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'request_type' => ['required', Rule::in(['missing_check_in', 'missing_check_out', 'missing_punch_pair', 'incorrect_punch', 'duplicate_punch', 'other'])],
            'reason' => ['required', 'string', 'min:10', 'max:5000', 'regex:/\S/'],
            'employee_note' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:10'],
            'items.*' => ['required', 'array:action,attendance_punch_id,punch_type,requested_punched_at,notes'],
            'items.*.action' => ['required', Rule::enum(AttendanceRegularizationItemAction::class)],
            'items.*.attendance_punch_id' => ['nullable', 'integer'],
            'items.*.punch_type' => ['nullable', Rule::in(['check_in', 'check_out'])],
            'items.*.requested_punched_at' => ['nullable', 'date'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $employeeId = $this->integer('employee_id');
            $company = $this->route('company');
            $companyId = $company instanceof Company ? $company->id : null;

            if ($employeeId && $companyId) {
                $employee = Employee::query()->where('company_id', $companyId)->find($employeeId);
                if (! $employee) {
                    $validator->errors()->add('employee_id', 'Choose an active employee in this company.');
                } elseif (! $this->user()?->can('createForEmployee', [RegularizationRequest::class, $company, $employee])) {
                    $validator->errors()->add('employee_id', 'You are not allowed to submit a request for this employee.');
                }
            }

            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item) || ! isset($item['action'])) {
                    continue;
                }

                $action = $item['action'];
                if ($action === 'add' || $action === 'replace') {
                    foreach (['punch_type', 'requested_punched_at'] as $field) {
                        if (blank($item[$field] ?? null)) {
                            $validator->errors()->add("items.{$index}.{$field}", 'This field is required for an added or replaced punch.');
                        }
                    }
                }

                if (in_array($action, ['replace', 'remove'], true) && blank($item['attendance_punch_id'] ?? null)) {
                    $validator->errors()->add("items.{$index}.attendance_punch_id", 'Select an original punch for replacement or removal.');
                }

                if ($action === 'add' && filled($item['attendance_punch_id'] ?? null)) {
                    $validator->errors()->add("items.{$index}.attendance_punch_id", 'Added punches cannot reference an existing punch.');
                }

                if ($action === 'remove' && (filled($item['punch_type'] ?? null) || filled($item['requested_punched_at'] ?? null))) {
                    $validator->errors()->add("items.{$index}", 'Removed punches cannot include replacement values.');
                }
            }
        }];
    }
}
