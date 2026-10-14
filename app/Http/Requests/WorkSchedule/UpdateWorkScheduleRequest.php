<?php

namespace App\Http\Requests\WorkSchedule;

use App\Models\WorkSchedule;

class UpdateWorkScheduleRequest extends WorkScheduleRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('workSchedule')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var WorkSchedule|null $schedule */
        $schedule = $this->route('workSchedule');

        return $this->scheduleRules($schedule, false);
    }
}
