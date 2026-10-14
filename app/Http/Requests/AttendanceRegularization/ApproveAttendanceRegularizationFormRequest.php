<?php

namespace App\Http\Requests\AttendanceRegularization;

use App\Models\AttendanceRegularizationRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveAttendanceRegularizationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        $regularization = $this->route('attendanceRegularizationRequest');

        return $regularization instanceof AttendanceRegularizationRequest
            && $this->user()?->can('submitApproval', $regularization) === true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'attendance_regularization_request_id' => ['prohibited'],
            'status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'review_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
