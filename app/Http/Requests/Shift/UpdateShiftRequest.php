<?php

namespace App\Http\Requests\Shift;

use App\Models\Shift;

class UpdateShiftRequest extends ShiftRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $shift = $this->route('shift');
        $user = $this->user();

        if (! $shift instanceof Shift || ! $user?->can('update', $shift)) {
            return false;
        }

        if ($this->input('status') === $shift->status->value) {
            return true;
        }

        $ability = $this->input('status') === 'active' ? 'activate' : 'deactivate';

        return $user->can($ability, $shift);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->shiftRules($this->route('shift'));
    }
}
