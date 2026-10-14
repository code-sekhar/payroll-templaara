<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class ApprovedLeaveCancellationException extends RuntimeException
{
    public const CODE = 'APPROVED_LEAVE_CANCELLATION_FAILED';

    public function __construct(
        public readonly int $leaveRequestId,
        public readonly string $reason,
    ) {
        parent::__construct("Approved leave cancellation failed for request {$leaveRequestId}: {$reason}.");
    }

    public function code(): string
    {
        return self::CODE;
    }
}
