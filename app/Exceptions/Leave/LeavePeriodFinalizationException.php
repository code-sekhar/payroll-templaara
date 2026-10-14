<?php

namespace App\Exceptions\Leave;

use RuntimeException;

final class LeavePeriodFinalizationException extends RuntimeException
{
    /**
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly ?int $accountId,
        public readonly string $reason,
        public readonly array $blockers = [],
    ) {
        parent::__construct("Leave period finalization failed for balance account {$accountId}: {$reason}.");
    }
}
