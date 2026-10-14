<?php

namespace App\Http\Requests\AttendancePunch;

use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebAttendancePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('selfPunch', [AttendancePunch::class, $company]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'punch_type' => ['required', Rule::enum(AttendancePunchType::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'company_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'source' => ['prohibited'],
            'created_by' => ['prohibited'],
            'punched_at' => ['prohibited'],
            'timezone' => ['prohibited'],
            'ip_address' => ['prohibited'],
            'user_agent' => ['prohibited'],
            'notes' => ['prohibited'],
            'device_identifier' => ['prohibited'],
            'external_reference' => ['prohibited'],
        ];
    }
}
