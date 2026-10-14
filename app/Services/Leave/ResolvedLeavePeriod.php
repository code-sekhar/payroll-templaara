<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;

final readonly class ResolvedLeavePeriod
{
    /** @param list<ResolvedLeaveDay> $days */
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public array $days,
        public string $requestedDays,
    ) {}
}
