<?php

namespace App\Actions\AttendancePolicies;

use App\Enums\PunchLocationMode;
use App\Models\AttendancePolicy;
use App\Models\AttendanceWorksite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAttendancePolicy
{
    /** @param array<string, mixed> $attributes */
    public function handle(AttendancePolicy $attendancePolicy, User $user, array $attributes): AttendancePolicy
    {
        return DB::transaction(function () use ($attendancePolicy, $user, $attributes): AttendancePolicy {
            $worksiteIds = array_map('intval', $attributes['worksite_ids'] ?? []);
            unset($attributes['worksite_ids']);
            if (($attributes['location_mode'] ?? $attendancePolicy->location_mode->value) === PunchLocationMode::GeofenceRequired->value && $worksiteIds === []) {
                throw ValidationException::withMessages(['worksite_ids' => 'Select at least one active worksite for geofence-required punching.']);
            }
            $lockedPolicy = AttendancePolicy::query()->where('company_id', $attendancePolicy->company_id)
                ->whereKey($attendancePolicy->getKey())->lockForUpdate()->firstOrFail();
            if ($worksiteIds !== []) {
                $validWorksiteIds = AttendanceWorksite::query()->where('company_id', $lockedPolicy->company_id)
                    ->where('is_active', true)->whereIn('id', $worksiteIds)->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                if (count($validWorksiteIds) !== count(array_unique($worksiteIds))) {
                    throw ValidationException::withMessages(['worksite_ids' => 'Select active worksites that belong to this company.']);
                }
            }

            $lockedPolicy->update([...$attributes, 'updated_by' => $user->id]);
            $lockedPolicy->worksites()->sync(array_fill_keys($worksiteIds, ['company_id' => (int) $lockedPolicy->company_id]));

            return $lockedPolicy->refresh();
        });
    }
}
