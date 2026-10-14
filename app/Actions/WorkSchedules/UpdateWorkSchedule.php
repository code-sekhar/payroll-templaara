<?php

namespace App\Actions\WorkSchedules;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;

class UpdateWorkSchedule
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, WorkSchedule $schedule, User $actor, array $attributes): WorkSchedule
    {
        abort_unless((int) $schedule->company_id === (int) $company->id, 404);

        return DB::transaction(function () use ($company, $schedule, $actor, $attributes): WorkSchedule {
            $schedule->newQuery()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $days = app(CreateWorkSchedule::class)->normalizeDays($company, $attributes['days'] ?? [], $schedule);

            $schedule->update([
                'name' => $attributes['name'],
                'code' => $attributes['code'],
                'description' => $attributes['description'] ?? null,
                'updated_by' => $actor->id,
            ]);

            $existingDays = $schedule->days()->get()->keyBy(fn ($day): int => $day->day_of_week->value);
            foreach ($days as $day) {
                $dayOfWeek = $day['day_of_week'];
                unset($day['company_id']);
                if ($existingDays->has($dayOfWeek)) {
                    $existingDays->get($dayOfWeek)->update($day);
                } else {
                    $schedule->days()->create($day);
                }
            }

            return $schedule->refresh()->load('days.shift');
        });
    }
}
