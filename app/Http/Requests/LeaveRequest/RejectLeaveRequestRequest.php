<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class RejectLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');
        $ability = $this->routeIs('companies.leave-requests.reject-from-inbox')
            ? 'submitRejectionDecision'
            : 'reject';

        return $leaveRequest instanceof LeaveRequest && $this->user()?->can($ability, $leaveRequest) === true;
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
            'review_note' => ['required', 'string', 'min:5', 'max:5000', 'regex:/\S/'],
        ];
    }
}
