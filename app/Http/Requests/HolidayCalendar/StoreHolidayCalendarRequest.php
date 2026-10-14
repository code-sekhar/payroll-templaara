<?php

namespace App\Http\Requests\HolidayCalendar;

use App\Models\HolidayCalendar;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayCalendarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', HolidayCalendar::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('holiday_calendars', 'name')->where('company_id', $companyId)->where('year', $this->input('year'))],
            'year' => ['required', 'integer', 'min:1900', 'max:2200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['prohibited'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }
}
