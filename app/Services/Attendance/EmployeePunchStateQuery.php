<?php

namespace App\Services\Attendance;

use App\Enums\AttendancePunchType;
use App\Enums\PunchLocationMode;
use App\Models\Company;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

final class EmployeePunchStateQuery
{
    public function __construct(
        private readonly LatestEmployeeAttendancePunchQuery $latestPunchQuery,
        private readonly PunchLocationPolicyResolver $locationPolicyResolver,
    ) {}

    public function forEmployee(Employee $employee, Company $company): EmployeePunchStateData
    {
        $timezone = $company->timezone ?: config('app.timezone', 'UTC');

        try {
            new DateTimeZone($timezone);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('The company timezone must be a valid IANA timezone.', previous: $exception);
        }

        $now = CarbonImmutable::now($timezone);
        $latestPunch = $this->latestPunchQuery->forEmployeeAt($employee, $company, $now);
        $locationPolicy = $this->locationPolicyResolver->resolve($employee, $company, $now, loadWorksites: false);
        $locationAvailable = $locationPolicy->mode !== PunchLocationMode::GeofenceRequired || $locationPolicy->hasWorksites;

        if ($latestPunch === null) {
            return new EmployeePunchStateData(
                state: 'not_started',
                canPunchIn: $locationAvailable,
                canPunchOut: false,
                reasonCode: $locationAvailable ? null : 'LOCATION_NOT_CONFIGURED',
                lastPunchType: null,
                lastPunchedAt: null,
                timezone: $timezone,
                locationMode: $locationPolicy->mode->value,
                locationAvailable: $locationAvailable,
                lastLocationResult: null,
            );
        }

        $punchedIn = $latestPunch->punch_type === AttendancePunchType::CheckIn;

        return new EmployeePunchStateData(
            state: $punchedIn ? 'clocked_in' : 'clocked_out',
            canPunchIn: ! $punchedIn && $locationAvailable,
            canPunchOut: $punchedIn && $locationAvailable,
            reasonCode: ! $locationAvailable ? 'LOCATION_NOT_CONFIGURED' : ($punchedIn ? 'ALREADY_PUNCHED_IN' : 'ALREADY_PUNCHED_OUT'),
            lastPunchType: $latestPunch->punch_type->value,
            lastPunchedAt: $latestPunch->punched_at->setTimezone($timezone)->toIso8601String(),
            timezone: $timezone,
            locationMode: $locationPolicy->mode->value,
            locationAvailable: $locationAvailable,
            lastLocationResult: in_array($latestPunch->location_result, ['captured', 'provided', 'verified'], true) ? $latestPunch->location_result : null,
        );
    }
}
