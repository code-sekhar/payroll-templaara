<?php

namespace App\Http\Requests\LeaveBalance;

use Illuminate\Foundation\Http\FormRequest;

class CloseLeaveBalanceAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('close', $this->route('leaveBalanceAccount')) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
