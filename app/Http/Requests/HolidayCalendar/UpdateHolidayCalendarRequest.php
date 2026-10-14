<?php

namespace App\Http\Requests\HolidayCalendar;

use App\Models\HolidayCalendar;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateHolidayCalendarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('holidayCalendar')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        /** @var HolidayCalendar $calendar */
        $calendar = $this->route('holidayCalendar');
        $companyId = app(ActiveCompany::class)->current()?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('holiday_calendars', 'name')->where('company_id', $companyId)->where('year', $this->input('year'))->ignore($calendar->id)],
            'year' => ['required', 'integer', 'min:1900', 'max:2200'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['prohibited'],
            'company_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('year')) {
                return;
            }

            /** @var HolidayCalendar $calendar */
            $calendar = $this->route('holidayCalendar');
            if ($calendar->holidays()->withTrashed()->whereYear('date', '<>', $this->integer('year'))->exists()) {
                $validator->errors()->add('year', 'The calendar year cannot change while it contains holidays from another year.');
            }
        }];
    }
}
