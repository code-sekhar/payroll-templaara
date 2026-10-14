<?php

namespace App\Services\Attendance;

final readonly class ApprovedLeaveAttendanceSynchronizationResult
{
    /**
     * @param  list<string>  $changedDates
     * @param  list<string>  $unchangedDates
     * @param  list<string>  $skippedFutureDates
     */
    public function __construct(
        public array $changedDates,
        public array $unchangedDates,
        public array $skippedFutureDates,
    ) {}
}
