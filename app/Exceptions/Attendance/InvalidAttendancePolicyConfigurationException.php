<?php

namespace App\Exceptions\Attendance;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class InvalidAttendancePolicyConfigurationException extends RuntimeException
{
    public const CODE = 'INVALID_ATTENDANCE_POLICY_CONFIGURATION';

    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $date,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Attendance policy configuration is invalid for employee {$employeeId} on {$date->toDateString()}.", previous: $previous);
    }

    public function code(): string
    {
        return self::CODE;
    }
}
