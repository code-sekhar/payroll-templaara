<?php

namespace App\Actions\WorkSchedules;

use App\Enums\WorkScheduleStatus;
use App\Models\User;
use App\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;

class ChangeWorkScheduleStatus
{
    public function handle(WorkSchedule $schedule, User $actor, WorkScheduleStatus $status): WorkSchedule
    {
        return DB::transaction(function () use ($schedule, $actor, $status): WorkSchedule {
            $schedule->newQuery()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $schedule->update(['status' => $status, 'updated_by' => $actor->id]);

            return $schedule->refresh();
        });
    }
}
