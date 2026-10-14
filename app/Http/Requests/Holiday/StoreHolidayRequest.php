<?php

namespace App\Http\Requests\Holiday;

use App\Models\Holiday;

class StoreHolidayRequest extends HolidayRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Holiday::class, $this->route('holidayCalendar')]) ?? false;
    }

    public function rules(): array
    {
        return $this->holidayRules();
    }
}
