<?php

namespace App\Actions\HolidayCalendars;

use App\Enums\HolidayCalendarStatus;
use App\Models\Company;
use App\Models\HolidayCalendar;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateHolidayCalendar
{
    /** @param array{name: string, year: int, description?: string|null} $attributes */
    public function handle(Company $company, User $actor, array $attributes): HolidayCalendar
    {
        return DB::transaction(fn (): HolidayCalendar => $company->holidayCalendars()->create([
            'name' => $attributes['name'],
            'year' => $attributes['year'],
            'description' => $attributes['description'] ?? null,
            'status' => HolidayCalendarStatus::Active,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]));
    }
}
