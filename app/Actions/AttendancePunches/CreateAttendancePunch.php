<?php

namespace App\Actions\AttendancePunches;

use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateAttendancePunch
{
    /** @param array<string, mixed> $metadata */
    public function handle(
        Company $company,
        Employee $employee,
        AttendancePunchType $punchType,
        CarbonInterface $punchedAt,
        string $timezone,
        AttendancePunchSource $source,
        ?User $actor,
        array $metadata = [],
    ): AttendancePunch {
        if (! $employee->exists || (int) $employee->company_id !== (int) $company->id
            || ! Employee::query()->where('company_id', $company->id)->whereKey($employee->getKey())->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Select an active employee in this company.']);
        }

        try {
            new DateTimeZone($timezone);
        } catch (Throwable) {
            throw ValidationException::withMessages(['timezone' => 'The company timezone must be a valid IANA timezone.']);
        }

        $punch = new AttendancePunch;
        $punch->forceFill([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'punch_type' => $punchType,
            'punched_at' => CarbonImmutable::instance($punchedAt)->utc(),
            'timezone' => $timezone,
            'source' => $source,
            'device_identifier' => $metadata['device_identifier'] ?? null,
            'external_reference' => $metadata['external_reference'] ?? null,
            'latitude' => $metadata['latitude'] ?? null,
            'longitude' => $metadata['longitude'] ?? null,
            'location_accuracy_meters' => $metadata['location_accuracy_meters'] ?? null,
            'location_mode' => $metadata['location_mode'] ?? null,
            'location_result' => $metadata['location_result'] ?? null,
            'worksite_id' => $metadata['worksite_id'] ?? null,
            'worksite_name_snapshot' => $metadata['worksite_name_snapshot'] ?? null,
            'location_distance_meters' => $metadata['location_distance_meters'] ?? null,
            'worksite_radius_meters_snapshot' => $metadata['worksite_radius_meters_snapshot'] ?? null,
            'ip_address' => $metadata['ip_address'] ?? null,
            'user_agent' => $metadata['user_agent'] ?? null,
            'notes' => $metadata['notes'] ?? null,
            'created_by' => $actor?->id,
        ])->save();

        return $punch;
    }
}
