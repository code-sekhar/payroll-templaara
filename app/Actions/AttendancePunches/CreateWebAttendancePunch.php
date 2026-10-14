<?php

namespace App\Actions\AttendancePunches;

use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendancePunchLocationVerifier;
use App\Services\Attendance\LatestEmployeeAttendancePunchQuery;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateWebAttendancePunch
{
    public function __construct(
        private readonly CreateAttendancePunch $createPunch,
        private readonly LatestEmployeeAttendancePunchQuery $latestPunchQuery,
        private readonly AttendancePunchLocationVerifier $locationVerifier,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, Employee $employee, User $actor, AttendancePunchType $punchType, array $attributes, ?string $ipAddress, ?string $userAgent): AttendancePunch
    {
        return DB::transaction(function () use ($company, $employee, $actor, $punchType, $attributes, $ipAddress, $userAgent): AttendancePunch {
            $lockedEmployee = Employee::query()
                ->where('company_id', $company->id)
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->first();

            if (! $lockedEmployee) {
                throw ValidationException::withMessages(['employee' => 'An active employee record linked to your account was not found in this company.']);
            }

            $timezone = $company->timezone ?: config('app.timezone', 'UTC');

            try {
                new DateTimeZone($timezone);
            } catch (Throwable) {
                throw ValidationException::withMessages(['timezone' => 'The company timezone must be a valid IANA timezone.']);
            }

            $punchedAt = CarbonImmutable::now($timezone);
            $latestPunch = $this->latestPunchQuery->forEmployeeAt($lockedEmployee, $company, $punchedAt);

            if ($punchType === AttendancePunchType::CheckOut && ! $latestPunch) {
                throw ValidationException::withMessages(['punch_type' => 'A check-out must follow a check-in.']);
            }

            if ($latestPunch?->punch_type === $punchType) {
                $nextEvent = $punchType === AttendancePunchType::CheckIn ? 'check-out' : 'check-in';
                throw ValidationException::withMessages(['punch_type' => "Record a {$nextEvent} before another {$punchType->value}."]);
            }

            $location = $this->locationVerifier->verify($lockedEmployee, $company, $punchedAt, $attributes);

            return $this->createPunch->handle(
                $company,
                $lockedEmployee,
                $punchType,
                $punchedAt,
                $timezone,
                AttendancePunchSource::Web,
                $actor,
                [
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'location_accuracy_meters' => $location['accuracy'],
                    'location_mode' => $location['mode'],
                    'location_result' => $location['result'],
                    'worksite_id' => $location['worksite_id'],
                    'worksite_name_snapshot' => $location['worksite_name_snapshot'],
                    'location_distance_meters' => $location['distance_meters'],
                    'worksite_radius_meters_snapshot' => $location['radius_meters_snapshot'],
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ],
            );
        });
    }
}
