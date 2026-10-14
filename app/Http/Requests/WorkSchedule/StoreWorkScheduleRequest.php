<?php

namespace App\Http\Requests\WorkSchedule;

use App\Models\WorkSchedule;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreWorkScheduleRequest extends WorkScheduleRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', WorkSchedule::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->scheduleRules(null, true);
    }
}
