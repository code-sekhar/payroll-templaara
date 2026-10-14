<?php

namespace App\Http\Requests\Attendance;

use App\Enums\CalculatedAttendanceStatus;
use App\Enums\DailyAttendanceRecordState;
use App\Models\Company;
use App\Models\DailyAttendanceRecord;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class IndexAttendanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $company = $this->route('company');

        if (! $company instanceof Company) {
            return false;
        }

        Gate::authorize('viewAny', [DailyAttendanceRecord::class, $company]);

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:120'],
            'department_id' => ['nullable', 'integer'],
            'designation_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (CalculatedAttendanceStatus $status): string => $status->value, CalculatedAttendanceStatus::cases()))],
            'record_state' => ['nullable', 'string', 'in:'.implode(',', array_map(fn (DailyAttendanceRecordState $state): string => $state->value, DailyAttendanceRecordState::cases()))],
            'regularization_status' => ['nullable', 'in:none,pending,approved,rejected,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
