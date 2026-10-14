<?php

namespace App\Actions\WorkSchedules;

use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;

class DeleteWorkSchedule
{
    public function handle(WorkSchedule $schedule, int $actorId): void
    {
        DB::transaction(function () use ($schedule, $actorId): void {
            $schedule->newQuery()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $schedule->update(['updated_by' => $actorId]);
            $schedule->delete();
        });
    }
}
