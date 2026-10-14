<?php

namespace App\Exceptions\Holidays;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final class InvalidHolidayCalendarConfigurationException extends RuntimeException
{
    public const CODE = 'INVALID_HOLIDAY_CALENDAR_CONFIGURATION';

    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $date,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Holiday calendar configuration is invalid for employee {$employeeId} on {$date->toDateString()}.", previous: $previous);
    }

    public function code(): string
    {
        return self::CODE;
    }
}
