<?php

namespace App\Http\Requests\AttendancePunch;

use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAttendancePunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('viewAny', [AttendancePunch::class, $company]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'punch_type' => ['nullable', Rule::enum(AttendancePunchType::class)],
            'source' => ['nullable', Rule::enum(AttendancePunchSource::class)],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
