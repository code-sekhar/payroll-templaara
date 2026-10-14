<?php

namespace App\Actions\AttendanceWorksites;

use App\Enums\PunchLocationMode;
use App\Models\AttendancePolicy;
use App\Models\AttendanceWorksite;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeAttendanceWorksiteStatus
{
    public function handle(AttendanceWorksite $attendanceWorksite, bool $isActive): AttendanceWorksite
    {
        return DB::transaction(function () use ($attendanceWorksite, $isActive): AttendanceWorksite {
            if (! $isActive) {
                AttendancePolicy::query()
                    ->where('company_id', $attendanceWorksite->company_id)
                    ->where('location_mode', PunchLocationMode::GeofenceRequired->value)
                    ->whereHas('worksites', fn ($query) => $query->where('attendance_worksites.id', $attendanceWorksite->getKey()))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }

            $lockedWorksite = AttendanceWorksite::query()
                ->where('company_id', $attendanceWorksite->company_id)
                ->whereKey($attendanceWorksite->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $isActive) {
                $geofencePolicies = AttendancePolicy::query()
                    ->where('company_id', $lockedWorksite->company_id)
                    ->where('location_mode', PunchLocationMode::GeofenceRequired->value)
                    ->whereHas('worksites', fn ($query) => $query->where('attendance_worksites.id', $lockedWorksite->getKey()))
                    ->orderBy('id')
                    ->get();

                foreach ($geofencePolicies as $policy) {
                    $otherActiveWorksites = $policy->worksites()
                        ->where('attendance_worksites.company_id', $lockedWorksite->company_id)
                        ->where('attendance_worksites.is_active', true)
                        ->where('attendance_worksites.id', '<>', $lockedWorksite->getKey())
                        ->count();

                    if ($otherActiveWorksites === 0) {
                        throw ValidationException::withMessages([
                            'worksite' => 'This is the only active worksite for a geofence-required attendance policy. Update the policy or add another worksite before deactivating it.',
                        ]);
                    }
                }
            }

            $lockedWorksite->update(['is_active' => $isActive]);

            return $lockedWorksite->refresh();
        });
    }
}
