<?php

namespace App\Exceptions\Leave;

use Carbon\CarbonImmutable;
use RuntimeException;

final class InvalidApprovedLeaveConfigurationException extends RuntimeException
{
    public const CODE = 'INVALID_APPROVED_LEAVE_CONFIGURATION';

    public function __construct(
        public readonly int $employeeId,
        public readonly CarbonImmutable $date,
        public readonly string $reason,
    ) {
        parent::__construct("Approved leave configuration is invalid for employee {$employeeId} on {$date->toDateString()}.");
    }

    public function code(): string
    {
        return self::CODE;
    }
}
