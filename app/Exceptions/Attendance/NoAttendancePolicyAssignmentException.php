<?php

namespace App\Exceptions\Attendance;

use Carbon\CarbonImmutable;
use RuntimeException;

final class NoAttendancePolicyAssignmentException extends RuntimeException
{
    public const REASON = 'NO_ATTENDANCE_POLICY_ASSIGNED';

    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $date,
    ) {
        parent::__construct("No active attendance policy assignment exists for employee {$employeeId} on {$date->toDateString()}.");
    }

    public function reason(): string
    {
        return self::REASON;
    }
}
