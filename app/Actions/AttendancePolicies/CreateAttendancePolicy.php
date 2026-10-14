<?php

namespace App\Actions\AttendancePolicies;

use App\Enums\PunchLocationMode;
use App\Models\AttendancePolicy;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAttendancePolicy
{
    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, User $user, array $attributes): AttendancePolicy
    {
        return DB::transaction(function () use ($company, $user, $attributes): AttendancePolicy {
            $worksiteIds = array_map('intval', $attributes['worksite_ids'] ?? []);
            unset($attributes['worksite_ids']);
            $this->assertLocationConfiguration($attributes['location_mode'] ?? PunchLocationMode::Disabled->value, $worksiteIds);
            if ($worksiteIds !== []) {
                $activeWorksiteIds = AttendanceWorksite::query()
                    ->where('company_id', $company->getKey())
                    ->where('is_active', true)
                    ->whereIn('id', $worksiteIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
                if (count($activeWorksiteIds) !== count(array_unique($worksiteIds))) {
                    throw ValidationException::withMessages(['worksite_ids' => 'Select active worksites that belong to this company.']);
                }
            }

            $policy = $company->attendancePolicies()->create([
                ...$attributes,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $policy->worksites()->sync($this->worksitePivot($worksiteIds, (int) $company->getKey()));

            return $policy;
        });
    }

    /** @param list<int> $worksiteIds
     * @return array<int, array{company_id: int}>
     */
    private function worksitePivot(array $worksiteIds, int $companyId): array
    {
        return array_fill_keys($worksiteIds, ['company_id' => $companyId]);
    }

    /** @param list<int> $worksiteIds */
    private function assertLocationConfiguration(string $locationMode, array $worksiteIds): void
    {
        if ($locationMode === PunchLocationMode::GeofenceRequired->value && $worksiteIds === []) {
            throw ValidationException::withMessages(['worksite_ids' => 'Select at least one active worksite for geofence-required punching.']);
        }
    }
}
