<?php

namespace App\Actions\HolidayCalendars;

use App\Enums\HolidayCalendarStatus;
use App\Models\HolidayCalendar;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ChangeHolidayCalendarStatus
{
    public function handle(HolidayCalendar $calendar, User $actor, HolidayCalendarStatus $status): HolidayCalendar
    {
        return DB::transaction(function () use ($calendar, $actor, $status): HolidayCalendar {
            $calendar->newQuery()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            $calendar->update(['status' => $status, 'updated_by' => $actor->id]);

            return $calendar->refresh();
        });
    }
}
