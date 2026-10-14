<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class ApproveLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');
        $ability = $this->routeIs('companies.leave-requests.approve-from-inbox')
            ? 'submitApprovalDecision'
            : 'approve';

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
            'review_note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
