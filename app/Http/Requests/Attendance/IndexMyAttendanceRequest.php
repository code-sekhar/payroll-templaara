<?php

namespace App\Http\Requests\Attendance;

use App\Enums\CalculatedAttendanceStatus;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class IndexMyAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && Gate::allows('viewOwnAny', [DailyAttendanceRecord::class, $company]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (CalculatedAttendanceStatus $status): string => $status->value, CalculatedAttendanceStatus::cases()))],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
