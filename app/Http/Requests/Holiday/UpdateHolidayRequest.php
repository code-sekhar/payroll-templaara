<?php

namespace App\Http\Requests\Holiday;

class UpdateHolidayRequest extends HolidayRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holiday')) ?? false;
    }

    public function rules(): array
    {
        return $this->holidayRules($this->route('holiday'));
    }
}
