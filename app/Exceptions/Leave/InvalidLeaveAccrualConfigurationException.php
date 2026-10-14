<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class InvalidLeaveAccrualConfigurationException extends RuntimeException
{
    public const CODE = 'INVALID_LEAVE_ACCRUAL_CONFIGURATION';

    public function __construct(
        public readonly ?int $accountId,
        public readonly string $reason,
    ) {
        parent::__construct("Leave accrual configuration is invalid for balance account {$accountId}: {$reason}.");
    }

    public function code(): string
    {
        return self::CODE;
    }
}
