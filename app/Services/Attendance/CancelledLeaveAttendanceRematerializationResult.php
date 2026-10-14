<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;

final readonly class CancelledLeaveAttendanceRematerializationResult
{
    /**
     * @param  list<string>  $affectedDates
     * @param  list<string>  $processedDates
     * @param  list<string>  $changedDates
     * @param  list<string>  $unchangedDates
     * @param  list<array{date: string, reason: string}>  $failedDates
     */
    public function __construct(
        public LeaveRequest $leaveRequest,
        public array $affectedDates,
        public array $processedDates,
        public array $changedDates,
        public array $unchangedDates,
        public array $failedDates,
    ) {}
}
