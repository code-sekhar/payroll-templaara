<?php

namespace App\Http\Requests\AttendanceWorksite;

use App\Models\AttendanceWorksite;
use Illuminate\Foundation\Http\FormRequest;

class StoreAttendanceWorksiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', AttendanceWorksite::class) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:1,10000'],
            'company_id' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }
}
