<?php

namespace App\Exceptions\WorkSchedules;

use Carbon\CarbonImmutable;
use RuntimeException;

final class NoWorkScheduleAssignmentException extends RuntimeException
{
    public const REASON = 'NO_SCHEDULE_ASSIGNED';

    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $date,
    ) {
        parent::__construct("No active work schedule assignment exists for employee {$employeeId} on {$date->toDateString()}.");
    }

    public function reason(): string
    {
        return self::REASON;
    }
}
