<?php

namespace App\Actions\HolidayCalendars;

use App\Models\Company;
use App\Models\HolidayCalendar;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateHolidayCalendar
{
    /** @param array{name: string, year: int, description?: string|null} $attributes */
    public function handle(Company $company, HolidayCalendar $calendar, User $actor, array $attributes): HolidayCalendar
    {
        abort_unless((int) $calendar->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($calendar, $actor, $attributes): HolidayCalendar {
            $lockedCalendar = $calendar->newQuery()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            if ($lockedCalendar->holidays()->withTrashed()->whereYear('date', '<>', $attributes['year'])->exists()) {
                throw ValidationException::withMessages(['year' => 'The calendar year cannot change while it contains holidays from another year.']);
            }
            $calendar->update([
                'name' => $attributes['name'],
                'year' => $attributes['year'],
                'description' => $attributes['description'] ?? null,
                'updated_by' => $actor->id,
            ]);

            return $calendar->refresh();
        });
    }
}
