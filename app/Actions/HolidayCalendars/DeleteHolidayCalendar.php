<?php

namespace App\Actions\HolidayCalendars;

use App\Models\HolidayCalendar;
use Illuminate\Support\Facades\DB;

class DeleteHolidayCalendar
{
    public function handle(HolidayCalendar $calendar, int $actorId): void
    {
        DB::transaction(function () use ($calendar, $actorId): void {
            $calendar->newQuery()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            $calendar->update(['updated_by' => $actorId]);
            $calendar->delete();
        });
    }
}
