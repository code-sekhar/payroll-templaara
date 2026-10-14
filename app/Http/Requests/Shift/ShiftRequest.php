<?php

namespace App\Http\Requests\Shift;

use App\Enums\ShiftStatus;
use App\Models\Shift;
use App\Tenancy\ActiveCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class ShiftRequest extends FormRequest
{
    /** @return array<string, array<mixed>> */
    protected function shiftRules(?Shift $shift = null): array
    {
        $companyId = app(ActiveCompany::class)->current()->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('shifts', 'name')->where('company_id', $companyId)->ignore($shift?->id)],
            'code' => ['required', 'string', 'max:64', Rule::unique('shifts', 'code')->where('company_id', $companyId)->ignore($shift?->id)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'break_duration_minutes' => ['required', 'integer', 'min:0', 'max:65535'],
            'grace_period_minutes' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'status' => ['required', Rule::enum(ShiftStatus::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'company_id' => ['prohibited'],
            'is_overnight' => ['prohibited'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['start_time', 'end_time', 'break_duration_minutes'])) {
                return;
            }

            $startTime = $this->string('start_time')->toString();
            $endTime = $this->string('end_time')->toString();
            $duration = Shift::durationMinutesFor($startTime, $endTime);

            if ($duration === 0) {
                $validator->errors()->add('end_time', 'The shift end time must differ from its start time.');

                return;
            }

            if ((int) $this->input('break_duration_minutes') > $duration) {
                $validator->errors()->add('break_duration_minutes', 'The break duration cannot exceed the shift duration.');
            }
        }];
    }
}
