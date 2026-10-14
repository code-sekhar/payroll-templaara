<?php

namespace App\Http\Requests\LeaveRequest;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Http\FormRequest;

class CancelLeaveRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leaveRequest = $this->route('leaveRequest');

        return $leaveRequest instanceof LeaveRequest && ($this->user()?->can('cancel', $leaveRequest) ?? false);
    }

    public function rules(): array
    {
        return ['cancellation_reason' => ['required', 'string', 'min:3', 'max:2000', 'regex:/\\S/']];
    }
}
