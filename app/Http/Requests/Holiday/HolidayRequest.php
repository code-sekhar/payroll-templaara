<?php

namespace App\Http\Requests\Holiday;

use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class HolidayRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    protected function holidayRules(?Holiday $holiday = null): array
    {
        /** @var HolidayCalendar $calendar */
        $calendar = $this->route('holidayCalendar');

        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['required', Rule::enum(HolidayType::class)],
            'status' => ['required', Rule::enum(HolidayStatus::class)],
            'is_optional' => ['required', 'boolean'],
            'company_id' => ['prohibited'],
            'holiday_calendar_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('date')) {
                return;
            }

            /** @var HolidayCalendar $calendar */
            $calendar = $this->route('holidayCalendar');
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $this->string('date')->toString());
            if ($date?->year !== $calendar->year) {
                $validator->errors()->add('date', 'The holiday date must be within the calendar year.');
            }

            if (HolidayStatus::tryFrom((string) $this->input('status')) !== HolidayStatus::Active) {
                return;
            }

            $duplicate = Holiday::query()->where('company_id', $calendar->company_id)
                ->where('holiday_calendar_id', $calendar->id)
                ->where('date', $date?->toDateString())
                ->where('status', HolidayStatus::Active)
                ->when($this->route('holiday') instanceof Holiday, fn ($query) => $query->whereKeyNot($this->route('holiday')->id))
                ->exists();

            if ($duplicate) {
                $validator->errors()->add('date', 'An active holiday already exists on this date in the calendar.');
            }
        }];
    }
}
