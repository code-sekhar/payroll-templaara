<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class LeavePeriodClosingAccountingException extends RuntimeException
{
    public const CODE = 'LEAVE_PERIOD_CLOSING_ACCOUNTING_FAILED';

    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly ?int $accountId,
        public readonly string $reason,
        public readonly array $blockers = [],
    ) {
        parent::__construct("Leave period closing accounting failed for balance account {$accountId}: {$reason}.");
    }

    public function code(): string
    {
        return self::CODE;
    }
}
