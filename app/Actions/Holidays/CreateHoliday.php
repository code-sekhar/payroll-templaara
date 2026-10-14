<?php

namespace App\Actions\Holidays;

use App\Enums\HolidayCalendarStatus;
use App\Enums\HolidayStatus;
use App\Enums\HolidayType;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\HolidayCalendar;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateHoliday
{
    /** @param array{date: string, name: string, description?: string|null, type: string, status: string, is_optional?: bool} $attributes */
    public function handle(Company $company, HolidayCalendar $calendar, User $actor, array $attributes): Holiday
    {
        abort_unless((int) $calendar->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $calendar, $actor, $attributes): Holiday {
            $lockedCalendar = $calendar->newQuery()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
            if ($lockedCalendar->status !== HolidayCalendarStatus::Active) {
                throw ValidationException::withMessages(['holiday_calendar' => 'New holidays can only be added to an active calendar.']);
            }
            $this->assertDateMatchesCalendarYear($lockedCalendar, $attributes['date']);

            if (HolidayStatus::from($attributes['status']) === HolidayStatus::Active
                && $lockedCalendar->holidays()->whereDate('date', $attributes['date'])->where('status', HolidayStatus::Active)->exists()) {
                throw ValidationException::withMessages(['date' => 'An active holiday already exists on this date in the calendar.']);
            }

            return $lockedCalendar->holidays()->create([
                'company_id' => $company->id,
                'date' => $attributes['date'],
                'name' => $attributes['name'],
                'description' => $attributes['description'] ?? null,
                'type' => HolidayType::from($attributes['type']),
                'status' => HolidayStatus::from($attributes['status']),
                'is_optional' => $attributes['is_optional'] ?? false,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
        });
    }

    private function assertDateMatchesCalendarYear(HolidayCalendar $calendar, string $date): void
    {
        if (CarbonImmutable::parse($date)->year !== $calendar->year) {
            throw ValidationException::withMessages(['date' => 'The holiday date must be within the calendar year.']);
        }
    }
}
