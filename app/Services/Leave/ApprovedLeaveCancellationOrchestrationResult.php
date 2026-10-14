<?php

namespace App\Services\Leave;

use App\Services\Attendance\CancelledLeaveAttendanceRematerializationResult;

final readonly class ApprovedLeaveCancellationOrchestrationResult
{
    /**
     * @param  list<array{date: ?string, reason: string}>  $attendanceFailedDates
     */
    public function __construct(
        public ApprovedLeaveCancellationResult $cancellationResult,
        public ?CancelledLeaveAttendanceRematerializationResult $attendanceResult,
        public bool $financialCancellationSucceeded,
        public bool $attendanceSynchronizationAttempted,
        public bool $attendanceSynchronizationSucceeded,
        public bool $attendanceSynchronizationPartiallyFailed,
        public array $attendanceFailedDates,
    ) {}
}
