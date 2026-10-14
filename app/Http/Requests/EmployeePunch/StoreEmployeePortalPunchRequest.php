<?php

namespace App\Http\Requests\EmployeePunch;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEmployeePortalPunchRequest extends FormRequest
{
    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return [
            'employee_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'attendance_date' => ['prohibited'],
            'punch_type' => ['prohibited'],
            'check_in' => ['prohibited'],
            'check_out' => ['prohibited'],
            'check_in_time' => ['prohibited'],
            'check_out_time' => ['prohibited'],
            'punched_at' => ['prohibited'],
            'timestamp' => ['prohibited'],
            'status' => ['prohibited'],
            'source' => ['prohibited'],
            'timezone' => ['prohibited'],
            'created_by' => ['prohibited'],
            'ip_address' => ['prohibited'],
            'user_agent' => ['prohibited'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'worksite_id' => ['prohibited'],
            'distance_meters' => ['prohibited'],
            'location_distance_meters' => ['prohibited'],
            'inside_geofence' => ['prohibited'],
            'geofence_passed' => ['prohibited'],
            'verified' => ['prohibited'],
            'location_required' => ['prohibited'],
            'location_mode' => ['prohibited'],
        ];
    }
}
