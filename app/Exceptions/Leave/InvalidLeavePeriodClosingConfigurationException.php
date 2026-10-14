<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class InvalidLeavePeriodClosingConfigurationException extends RuntimeException
{
    public const CODE = 'INVALID_LEAVE_PERIOD_CLOSING_CONFIGURATION';

    public function __construct(
        public readonly ?int $accountId,
        public readonly string $reason,
    ) {
        parent::__construct("Leave period closing configuration is invalid for balance account {$accountId}: {$reason}.");
    }

    public function code(): string
    {
        return self::CODE;
    }
}
