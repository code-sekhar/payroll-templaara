<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeAttendanceIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'month' => ['sometimes', 'string', 'regex:/^(?!0000)[0-9]{4}-(0[1-9]|1[0-2])$/'],
            'employee_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
