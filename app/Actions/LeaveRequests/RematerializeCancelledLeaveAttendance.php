<?php

namespace App\Actions\LeaveRequests;

use App\Exceptions\Leave\ApprovedLeaveCancellationException;
use App\Models\DailyAttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Services\Attendance\CancelledLeaveAttendanceRematerializationResult;
use App\Services\Attendance\DailyAttendanceMaterializer;
use Carbon\CarbonImmutable;
use Throwable;

final class RematerializeCancelledLeaveAttendance
{
    public function __construct(
        private readonly CancelApprovedLeaveRequest $cancellationVerifier,
        private readonly DailyAttendanceMaterializer $materializer,
    ) {}

    public function execute(LeaveRequest $leaveRequest): CancelledLeaveAttendanceRematerializationResult
    {
        $verified = $this->cancellationVerifier->verifyCancelled($leaveRequest);
        $request = $verified->leaveRequest;
        $employee = Employee::withTrashed()->where('company_id', $request->company_id)
            ->whereKey($request->employee_id)->first();

        if (! $employee || ! $employee->company) {
            throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_REFERENCE_MISMATCH');
        }

        $days = $request->days()
            ->where('requested_amount', '>', 0)
            ->orderBy('leave_date')
            ->orderBy('id')
            ->get();
        $affectedDates = $days->map(fn ($day): string => $day->leave_date->toDateString())->all();
        $processedDates = [];
        $changedDates = [];
        $unchangedDates = [];
        $failedDates = [];
        $seenDates = [];

        foreach ($days as $day) {
            $date = $day->leave_date->toDateString();
            try {
                if ((int) $day->company_id !== (int) $request->company_id
                    || (int) $day->employee_id !== (int) $employee->getKey()
                    || (int) $day->leave_request_id !== (int) $request->getKey()
                    || $date < $request->start_date->toDateString()
                    || $date > $request->end_date->toDateString()
                    || isset($seenDates[$date])) {
                    throw new ApprovedLeaveCancellationException((int) $request->getKey(), 'APPROVED_LEAVE_DAY_REFERENCE_MISMATCH');
                }
                $seenDates[$date] = true;

                $record = DailyAttendanceRecord::query()
                    ->where('company_id', $request->company_id)
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('attendance_date', $date)
                    ->first();
                $historyCount = $record?->histories()->count() ?? 0;

                $this->materializer->materialize(
                    $employee,
                    CarbonImmutable::parse($date, $employee->company->timezone ?: config('app.timezone', 'UTC')),
                );

                $updatedHistoryCount = DailyAttendanceRecord::query()
                    ->where('company_id', $request->company_id)
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('attendance_date', $date)
                    ->firstOrFail()
                    ->histories()->count();

                $processedDates[] = $date;
                if ($updatedHistoryCount > $historyCount) {
                    $changedDates[] = $date;
                } else {
                    $unchangedDates[] = $date;
                }
            } catch (Throwable $exception) {
                $failedDates[] = [
                    'date' => $date,
                    'reason' => $exception instanceof ApprovedLeaveCancellationException
                        ? $exception->reason
                        : class_basename($exception),
                ];
            }
        }

        return new CancelledLeaveAttendanceRematerializationResult(
            $request,
            $affectedDates,
            $processedDates,
            $changedDates,
            $unchangedDates,
            $failedDates,
        );
    }
}
