<?php

namespace App\Actions\AttendancePunches;

use App\Enums\AttendancePunchSource;
use App\Enums\AttendancePunchType;
use App\Models\AttendancePunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CreateManualAttendancePunch
{
    public function __construct(private readonly CreateAttendancePunch $createPunch) {}

    /** @param array<string, mixed> $attributes */
    public function handle(Company $company, Employee $employee, User $actor, array $attributes, ?string $ipAddress, ?string $userAgent): AttendancePunch
    {
        return DB::transaction(function () use ($company, $employee, $actor, $attributes, $ipAddress, $userAgent): AttendancePunch {
            $timezone = $company->timezone ?: config('app.timezone', 'UTC');

            try {
                new DateTimeZone($timezone);
                $punchedAt = CarbonImmutable::createFromFormat('!Y-m-d\\TH:i', $attributes['punched_at'], $timezone);
            } catch (Throwable) {
                throw ValidationException::withMessages(['punched_at' => 'Enter a valid local date and time for the employee’s company timezone.']);
            }

            if (! $punchedAt) {
                throw ValidationException::withMessages(['punched_at' => 'Enter a valid local date and time for the employee’s company timezone.']);
            }

            return $this->createPunch->handle(
                $company,
                $employee,
                AttendancePunchType::from($attributes['punch_type']),
                $punchedAt,
                $timezone,
                AttendancePunchSource::Manual,
                $actor,
                [
                    'notes' => $attributes['notes'],
                    'latitude' => $attributes['latitude'] ?? null,
                    'longitude' => $attributes['longitude'] ?? null,
                    'location_accuracy_meters' => $attributes['location_accuracy_meters'] ?? null,
                    'device_identifier' => $attributes['device_identifier'] ?? null,
                    'external_reference' => $attributes['external_reference'] ?? null,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ],
            );
        });
    }
}
