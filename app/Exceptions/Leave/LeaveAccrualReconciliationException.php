<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class LeaveAccrualReconciliationException extends RuntimeException
{
    public const CODE = 'LEAVE_ACCRUAL_RECONCILIATION_FAILED';

    public function __construct(
        public readonly ?int $accountId,
        public readonly string $reason,
    ) {
        parent::__construct("Leave accrual reconciliation failed for balance account {$accountId}: {$reason}.");
    }

    public function code(): string
    {
        return self::CODE;
    }
}
