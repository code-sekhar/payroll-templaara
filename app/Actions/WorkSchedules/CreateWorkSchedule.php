<?php

namespace App\Actions\WorkSchedules;

use App\Enums\DayOfWeek;
use App\Enums\WorkScheduleDayType;
use App\Enums\WorkScheduleStatus;
use App\Models\Company;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateWorkSchedule
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $actor, array $attributes): WorkSchedule
    {
        return DB::transaction(function () use ($company, $actor, $attributes): WorkSchedule {
            $days = $this->normalizeDays($company, $attributes['days'] ?? []);
            $schedule = $company->workSchedules()->create([
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'description' => $attributes['description'] ?? null,
                'status' => $attributes['status'] ?? WorkScheduleStatus::Active,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $schedule->days()->createMany($days);

            return $schedule->load('days.shift');
        });
    }

    /** @param array<mixed> $submittedDays
     * @return list<array{company_id: int, day_of_week: int, day_type: string, shift_id: int|null}>
     */
    public function normalizeDays(Company $company, array $submittedDays, ?WorkSchedule $schedule = null): array
    {
        if (count($submittedDays) !== 7) {
            throw ValidationException::withMessages(['days' => 'Configure each weekday exactly once.']);
        }

        $existingShiftIds = $schedule?->days()->whereNotNull('shift_id')->pluck('shift_id')->map(fn ($id): int => (int) $id)->all() ?? [];
        $requestedShiftIds = [];
        $dayValues = [];
        foreach ($submittedDays as $index => $day) {
            if (! is_array($day) || ! isset($day['day_of_week'], $day['day_type']) || ! is_numeric($day['day_of_week'])) {
                throw ValidationException::withMessages(["days.{$index}" => 'Each weekday requires a valid day and day type.']);
            }

            $dayOfWeek = DayOfWeek::tryFrom((int) $day['day_of_week']);
            $dayType = WorkScheduleDayType::tryFrom((string) $day['day_type']);
            if ($dayOfWeek === null || $dayType === null || in_array($dayOfWeek->value, $dayValues, true)) {
                throw ValidationException::withMessages(["days.{$index}.day_of_week" => 'Each weekday must appear exactly once.']);
            }

            $dayValues[] = $dayOfWeek->value;
            $shiftId = filled($day['shift_id'] ?? null) ? (int) $day['shift_id'] : null;
            if ($dayType === WorkScheduleDayType::Off && $shiftId !== null) {
                throw ValidationException::withMessages(["days.{$index}.shift_id" => 'Off days cannot have a shift.']);
            }
            if ($dayType !== WorkScheduleDayType::Off && $shiftId === null) {
                throw ValidationException::withMessages(["days.{$index}.shift_id" => 'Select a shift for working and half-day entries.']);
            }
            if ($shiftId !== null) {
                $requestedShiftIds[] = $shiftId;
            }
        }

        sort($dayValues);
        $validDayValues = array_map(fn (DayOfWeek $day): int => $day->value, DayOfWeek::cases());
        sort($validDayValues);
        if ($dayValues !== $validDayValues) {
            throw ValidationException::withMessages(['days' => 'Configure each weekday exactly once.']);
        }

        $shifts = Shift::query()->where('company_id', $company->id)
            ->whereIn('id', array_unique($requestedShiftIds))
            ->where(fn ($query) => $query->where('status', 'active')->orWhereIn('id', $existingShiftIds))
            ->lockForUpdate()->get(['id'])->keyBy('id');

        foreach ($submittedDays as $index => $day) {
            $shiftId = filled($day['shift_id'] ?? null) ? (int) $day['shift_id'] : null;
            if ($shiftId !== null && ! $shifts->has($shiftId)) {
                throw ValidationException::withMessages(["days.{$index}.shift_id" => 'Select an active shift belonging to this company.']);
            }
        }

        $normalized = [];
        foreach ($submittedDays as $day) {
            $normalized[] = [
                'company_id' => $company->id,
                'day_of_week' => (int) $day['day_of_week'],
                'day_type' => (string) $day['day_type'],
                'shift_id' => filled($day['shift_id'] ?? null) ? (int) $day['shift_id'] : null,
            ];
        }

        return $normalized;
    }
}
