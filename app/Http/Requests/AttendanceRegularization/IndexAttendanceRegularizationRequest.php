<?php

namespace App\Http\Requests\AttendanceRegularization;

use App\Models\AttendanceRegularizationRequest as RegularizationRequest;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAttendanceRegularizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user()?->can('viewAny', [RegularizationRequest::class, $company]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'request_type' => ['nullable', Rule::in(['missing_check_in', 'missing_check_out', 'missing_punch_pair', 'incorrect_punch', 'duplicate_punch', 'other'])],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'requester' => ['nullable', 'string', 'max:100'],
        ];
    }
}
