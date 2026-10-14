<?php

namespace App\Services\Attendance;

use App\Enums\PunchLocationMode;
use App\Models\AttendanceWorksite;

final readonly class PunchLocationPolicyData
{
    /** @param list<AttendanceWorksite> $worksites */
    public function __construct(
        public PunchLocationMode $mode,
        public array $worksites = [],
        public bool $hasWorksites = false,
    ) {}
}
