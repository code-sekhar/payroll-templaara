<?php

namespace App\Actions\Shifts;

use App\Models\Shift;

class UpdateShift
{
    /** @param array<string, mixed> $attributes */
    public function handle(Shift $shift, array $attributes): Shift
    {
        $attributes['is_overnight'] = Shift::isOvernightTime($attributes['start_time'], $attributes['end_time']);
        $shift->update($attributes);

        return $shift;
    }
}
