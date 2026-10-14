<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelApprovedLeaveHttpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest
            && ($this->user()?->can('cancelApproved', $leaveRequest) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'company_id' => ['prohibited'],
            'employee_id' => ['prohibited'],
            'status' => ['prohibited'],
            'reviewed_by' => ['prohibited'],
            'reviewed_at' => ['prohibited'],
            'cancelled_by' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancellation_reason' => ['required', 'string', 'min:3', 'max:1000', 'regex:/\\S/'],
        ];
    }
}
