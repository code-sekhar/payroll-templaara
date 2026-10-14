<?php

namespace App\Http\Requests;

use App\Enums\AttendanceRegularizationItemAction;
use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use App\Models\Employee;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EmployeeAttendanceCorrectionStoreRequest extends FormRequest
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
            'company_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
            'requested_by' => ['prohibited'],
            'requested_at' => ['prohibited'],
            'daily_attendance_record_id' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_note' => ['prohibited'],
            'attendance_date' => ['required', 'date_format:Y-m-d'],
            'request_type' => ['required', Rule::in(['missing_check_in', 'missing_check_out', 'missing_punch_pair', 'incorrect_punch', 'duplicate_punch'])],
            'reason' => ['required', 'string', 'min:10', 'max:5000', 'regex:/\S/'],
            'employee_note' => ['prohibited'],
            'items' => ['required', 'array', 'min:1', 'max:2'],
            'items.*' => ['required', 'array:action,punch_reference,punch_type,requested_punched_at,notes'],
            'items.*.action' => ['required', Rule::enum(AttendanceRegularizationItemAction::class)],
            'items.*.punch_reference' => ['nullable', 'string', 'size:64'],
            'items.*.attendance_punch_id' => ['prohibited'],
            'items.*.punch_type' => ['nullable', Rule::in(['check_in', 'check_out'])],
            'items.*.requested_punched_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'items.*.notes' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item) || ! isset($item['action'])) {
                    continue;
                }

                $action = $item['action'];
                if (in_array($action, ['add', 'replace'], true)) {
                    foreach (['punch_type', 'requested_punched_at'] as $field) {
                        if (blank($item[$field] ?? null)) {
                            $validator->errors()->add("items.{$index}.{$field}", 'Enter the requested punch type and time.');
                        }
                    }
                }

                if (in_array($action, ['replace', 'remove'], true) && blank($item['punch_reference'] ?? null)) {
                    $validator->errors()->add("items.{$index}.punch_reference", 'Select an original punch.');
                }

                if ($action === 'add' && filled($item['punch_reference'] ?? null)) {
                    $validator->errors()->add("items.{$index}.punch_reference", 'A new punch cannot reference an existing punch.');
                }

                if ($action === 'remove' && (filled($item['punch_type'] ?? null) || filled($item['requested_punched_at'] ?? null))) {
                    $validator->errors()->add("items.{$index}", 'A removed punch cannot include replacement details.');
                }
            }

            $type = $this->input('request_type');
            $items = array_values(array_filter((array) $this->input('items', []), 'is_array'));
            $actions = array_map(fn (array $item): string => (string) ($item['action'] ?? ''), $items);
            $punchTypes = array_map(fn (array $item): ?string => $item['punch_type'] ?? null, $items);
            $consistent = match ($type) {
                'missing_check_in' => count($items) === 1 && $actions === ['add'] && $punchTypes === ['check_in'],
                'missing_check_out' => count($items) === 1 && $actions === ['add'] && $punchTypes === ['check_out'],
                'missing_punch_pair' => count($items) === 2 && $actions === ['add', 'add'] && count(array_filter($punchTypes, fn (?string $punchType): bool => $punchType === 'check_in')) === 1 && count(array_filter($punchTypes, fn (?string $punchType): bool => $punchType === 'check_out')) === 1,
                'incorrect_punch' => count($items) === 1 && $actions === ['replace'],
                'duplicate_punch' => count($items) === 1 && $actions === ['remove'],
                default => false,
            };

            if (! $consistent) {
                $validator->errors()->add('items', 'The requested correction details do not match the selected correction type.');
            }

            $company = app(ActiveCompany::class)->current();
            $employee = $this->attributes->get('employee_portal.employee');
            $date = $this->input('attendance_date');
            if ($company instanceof Company && $employee instanceof Employee && is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $localToday = now($company->timezone ?: config('app.timezone', 'UTC'))->toDateString();
                if ($date > $localToday) {
                    $validator->errors()->add('attendance_date', 'Choose a date that has already occurred.');
                }
                if ($employee->joining_date && $date < $employee->joining_date->toDateString()) {
                    $validator->errors()->add('attendance_date', 'Choose a date on or after your employment start date.');
                }
            }
        }];
    }
}
