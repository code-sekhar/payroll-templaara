<?php

namespace App\Actions\Shifts;

use App\Models\Company;
use App\Models\Shift;

class CreateShift
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, array $attributes): Shift
    {
        $attributes['is_overnight'] = Shift::isOvernightTime($attributes['start_time'], $attributes['end_time']);

        return $company->shifts()->create($attributes);
    }
}
