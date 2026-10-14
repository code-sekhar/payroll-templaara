<?php

namespace App\Services\Attendance;

use App\Enums\PunchLocationMode;
use App\Models\AttendanceWorksite;
use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

final class AttendancePunchLocationVerifier
{
    public function __construct(private readonly PunchLocationPolicyResolver $policyResolver) {}

    /**
     * @param  array<string, mixed>  $coordinates
     * @return array{mode: string, result: string, latitude: ?string, longitude: ?string, accuracy: ?string, worksite_id: ?int, worksite_name_snapshot: ?string, distance_meters: ?string, radius_meters_snapshot: ?int}
     */
    public function verify(Employee $employee, Company $company, CarbonInterface $punchedAt, array $coordinates): array
    {
        $policy = $this->policyResolver->resolve($employee, $company, $punchedAt, true);
        $latitude = $coordinates['latitude'] ?? null;
        $longitude = $coordinates['longitude'] ?? null;
        $accuracy = $coordinates['location_accuracy_meters'] ?? null;
        $hasLatitude = $latitude !== null && $latitude !== '';
        $hasLongitude = $longitude !== null && $longitude !== '';

        if ($policy->mode === PunchLocationMode::Disabled) {
            return $this->result($policy->mode, 'not_requested');
        }

        if (! $hasLatitude && ! $hasLongitude) {
            if ($policy->mode === PunchLocationMode::Capture) {
                return $this->result($policy->mode, 'not_captured');
            }

            throw ValidationException::withMessages(['location' => 'Location access is required to punch for this company.']);
        }

        if (! $hasLatitude || ! $hasLongitude) {
            throw ValidationException::withMessages(['location' => 'A complete location reading is required to punch.']);
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        $accuracy = $accuracy === null || $accuracy === '' ? null : (string) $accuracy;

        if ($policy->mode !== PunchLocationMode::GeofenceRequired) {
            $result = $policy->mode === PunchLocationMode::Capture ? 'captured' : 'provided';

            return $this->result($policy->mode, $result, $latitude, $longitude, $accuracy);
        }

        if ($policy->worksites === []) {
            throw ValidationException::withMessages(['location' => 'Attendance location is not configured. Contact HR.']);
        }

        $matches = [];
        foreach ($policy->worksites as $worksite) {
            $distance = $this->distanceMeters($latitude, $longitude, (float) $worksite->latitude, (float) $worksite->longitude);
            if ($distance <= (int) $worksite->radius_meters) {
                $matches[] = ['worksite' => $worksite, 'distance' => $distance];
            }
        }

        if ($matches === []) {
            throw ValidationException::withMessages(['location' => 'You are outside the allowed attendance location.']);
        }

        usort($matches, static fn (array $left, array $right): int => [$left['distance'], $left['worksite']->getKey()] <=> [$right['distance'], $right['worksite']->getKey()]);
        /** @var AttendanceWorksite $worksite */
        $worksite = $matches[0]['worksite'];

        return $this->result(
            $policy->mode,
            'verified',
            $latitude,
            $longitude,
            $accuracy,
            (int) $worksite->getKey(),
            $worksite->name,
            number_format($matches[0]['distance'], 2, '.', ''),
            (int) $worksite->radius_meters,
        );
    }

    private function distanceMeters(float $latitude, float $longitude, float $worksiteLatitude, float $worksiteLongitude): float
    {
        $earthRadiusMeters = 6371000.0;
        $latitudeDelta = deg2rad($worksiteLatitude - $latitude);
        $longitudeDelta = deg2rad($worksiteLongitude - $longitude);
        $firstLatitude = deg2rad($latitude);
        $secondLatitude = deg2rad($worksiteLatitude);
        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($firstLatitude) * cos($secondLatitude) * sin($longitudeDelta / 2) ** 2;

        return $earthRadiusMeters * 2 * atan2(sqrt($haversine), sqrt(max(0.0, 1 - $haversine)));
    }

    /** @return array{mode: string, result: string, latitude: ?string, longitude: ?string, accuracy: ?string, worksite_id: ?int, worksite_name_snapshot: ?string, distance_meters: ?string, radius_meters_snapshot: ?int} */
    private function result(
        PunchLocationMode $mode,
        string $result,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $accuracy = null,
        ?int $worksiteId = null,
        ?string $worksiteName = null,
        ?string $distance = null,
        ?int $radius = null,
    ): array {
        return [
            'mode' => $mode->value,
            'result' => $result,
            'latitude' => $latitude === null ? null : number_format($latitude, 7, '.', ''),
            'longitude' => $longitude === null ? null : number_format($longitude, 7, '.', ''),
            'accuracy' => $accuracy,
            'worksite_id' => $worksiteId,
            'worksite_name_snapshot' => $worksiteName,
            'distance_meters' => $distance,
            'radius_meters_snapshot' => $radius,
        ];
    }
}
