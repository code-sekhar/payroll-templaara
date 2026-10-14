<?php

namespace App\Http\Requests\AttendancePunch;

use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManualAttendancePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('createManual', [AttendancePunch::class, $company]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'punch_type' => ['required', Rule::enum(AttendancePunchType::class)],
            'punched_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'notes' => ['required', 'string', 'min:5', 'max:10000'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'device_identifier' => ['nullable', 'string', 'max:255'],
            'external_reference' => [
                'nullable', 'string', 'max:255',
                Rule::unique('attendance_punches', 'external_reference')
                    ->where('company_id', $companyId)
                    ->where('source', 'manual'),
            ],
            'company_id' => ['prohibited'],
            'source' => ['prohibited'],
            'created_by' => ['prohibited'],
            'timezone' => ['prohibited'],
            'ip_address' => ['prohibited'],
            'user_agent' => ['prohibited'],
        ];
    }
}
