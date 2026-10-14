<?php

namespace App\Actions\Holidays;

use App\Models\Holiday;
use Illuminate\Support\Facades\DB;

class DeleteHoliday
{
    public function handle(Holiday $holiday, int $actorId): void
    {
        DB::transaction(function () use ($holiday, $actorId): void {
            $holiday->newQuery()->whereKey($holiday->id)->lockForUpdate()->firstOrFail();
            $holiday->update(['updated_by' => $actorId]);
            $holiday->delete();
        });
    }
}
