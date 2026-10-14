<?php

namespace App\Http\Requests\Shift;

use App\Models\Shift;

class StoreShiftRequest extends ShiftRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Shift::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->shiftRules();
    }
}
