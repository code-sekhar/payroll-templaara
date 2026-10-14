<?php

namespace App\Exceptions\WorkSchedules;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class InvalidWorkScheduleConfigurationException extends RuntimeException
{
    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $date,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Work schedule configuration is invalid for employee {$employeeId} on {$date->toDateString()}.", previous: $previous);
    }
}
