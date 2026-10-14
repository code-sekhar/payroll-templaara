<?php

namespace App\Actions\LeaveRequests;

use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Leave\ApprovedLeaveCancellationOrchestrationResult;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CancelApprovedLeaveAndSynchronizeAttendance
{
    public function __construct(
        private readonly CancelApprovedLeaveRequest $cancellation,
        private readonly RematerializeCancelledLeaveAttendance $attendance,
    ) {}

    public function execute(LeaveRequest $leaveRequest, User $actor, string $reason): ApprovedLeaveCancellationOrchestrationResult
    {
        $cancellationResult = $this->cancellation->execute($leaveRequest, $actor, $reason);

        try {
            $attendanceResult = $this->attendance->execute($cancellationResult->leaveRequest);
            $failedDates = array_map(
                fn (array $failure): array => ['date' => $failure['date'], 'reason' => $failure['reason']],
                $attendanceResult->failedDates,
            );

            return new ApprovedLeaveCancellationOrchestrationResult(
                $cancellationResult,
                $attendanceResult,
                true,
                true,
                $failedDates === [],
                $failedDates !== [],
                $failedDates,
            );
        } catch (Throwable $exception) {
            Log::warning('Approved leave was cancelled but attendance synchronization could not complete.', [
                'leave_request_id' => $cancellationResult->leaveRequest->getKey(),
                'exception' => $exception,
            ]);

            return new ApprovedLeaveCancellationOrchestrationResult(
                $cancellationResult,
                null,
                true,
                true,
                false,
                true,
                [[
                    'date' => null,
                    'reason' => $exception instanceof ApprovedLeaveCancellationException
                        ? $exception->reason
                        : 'ATTENDANCE_SYNCHRONIZATION_FAILED',
                ]],
            );
        }
    }
}
